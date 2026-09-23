<?php

namespace App\Service\Invoice;

use App\Entity\InvoiceSettings;
use App\Entity\MembershipFee;
use App\Entity\TrainingSeason;
use App\Entity\User;
use App\Entity\UserSeasonMembership;
use App\Enum\PaymentType;
use App\Enum\Profile;
use App\Repository\BannerRepository;
use App\Repository\InvoiceSettingsRepository;
use App\Repository\MembershipFeeRepository;
use App\Repository\UserSeasonMembershipRepository;
use App\Service\Csv\CsvImportService;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Résolution du tarif applicable + génération de la facture PDF.
 *
 * Nécessite dompdf/dompdf (composer require dompdf/dompdf). Sans lui,
 * la génération PDF lève une exception explicite.
 */
class InvoiceService
{
    public function __construct(
        private readonly MembershipFeeRepository $fees,
        private readonly InvoiceSettingsRepository $settings,
        private readonly UserSeasonMembershipRepository $memberships,
        private readonly BannerRepository $banners,
        private readonly EntityManagerInterface $em,
        private readonly Environment $twig,
        private readonly string $signatureDir,
        private readonly string $publicDir,
    ) {
    }

    /**
     * Résout le triplet (profil tarifaire, type de licence, montant en €).
     * Retourne null pour amount si aucun tarif ne matche.
     *
     * @return array{profile: string, typeLicence: string, fee: ?MembershipFee}
     */
    public function resolveFee(User $user, TrainingSeason $season, ?UserSeasonMembership $membership = null): array
    {
        // Profil tarifaire :
        //  1. Override manuel : si l'admin a fixé un tariffProfile sur la
        //     membership (ex : « u25 »), on l'utilise tel quel — permet de
        //     facturer un tarif spécial (U25, etc.) sans que ce soit un
        //     profil utilisateur.
        //  2. Sinon auto-derive : Jeune s'il l'est, sinon Sénior par défaut.
        $override = $membership?->getTariffProfile();
        if ($override !== null && in_array($override, MembershipFee::APPLICABLE_PROFILES, true)) {
            $profile = $override;
        } else {
            $userProfiles = $user->getProfiles();
            $profile = in_array(Profile::Jeune->value, $userProfiles, true)
                ? Profile::Jeune->value
                : Profile::Senior->value;
        }

        // Type licence : snapshot du membership (spécifique à la saison),
        // fallback sur la valeur courante du user. Re-normalisation
        // défensive : les snapshots pré-normalisation stockaient parfois
        // la valeur brute FFTri (« Compétition 2026-27 - Sénior »), qui ne
        // matcherait pas la comparaison stricte contre MembershipFee::TYPES.
        $rawType = $membership?->getTypeLicence() ?? $user->getTypeLicence();
        $type = null;
        if ($rawType !== null) {
            $type = CsvImportService::normalizeTypeLicence($rawType) ?? $rawType;
        }
        if (!in_array($type, MembershipFee::TYPES, true)) {
            $type = MembershipFee::TYPE_LOISIR;
        }

        $fee = $this->fees->findOneByCriteria($season, $profile, $type);

        return ['profile' => $profile, 'typeLicence' => $type, 'fee' => $fee];
    }

    /**
     * Génère le PDF de la facture pour ce (user, saison). Nécessite un
     * UserSeasonMembership existant. Retourne le contenu binaire du PDF.
     *
     * @throws \RuntimeException si dompdf n'est pas installé, si la
     *                          membership est absente ou si le tarif
     *                          n'est pas défini.
     */
    public function renderPdf(User $user, TrainingSeason $season): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException(
                'La librairie dompdf/dompdf n\'est pas installée. Lancez : '
                .'composer require dompdf/dompdf'
            );
        }
        $membership = $this->memberships->findOneByUserAndSeason($user, $season);
        if ($membership === null) {
            throw new \RuntimeException(sprintf(
                'Aucune adhésion enregistrée pour %s à la saison %s.',
                $user->getFullName(), (string) $season,
            ));
        }
        $resolved = $this->resolveFee($user, $season, $membership);
        if ($resolved['fee'] === null) {
            throw new \RuntimeException(sprintf(
                'Aucun tarif défini pour (%s, %s, saison %s). Renseignez la grille tarifaire.',
                $resolved['profile'], $resolved['typeLicence'], (string) $season,
            ));
        }

        $settings = $this->settings->findCurrent();
        if ($settings === null) {
            throw new \RuntimeException('Paramètres facturation absents — configurez-les dans « Facturation → Paramètres ».');
        }

        // Signature : passe une data URI base64 si disponible (dompdf gère
        // mal les URLs relatives depuis un flux HTML in-memory).
        $signatureDataUri = null;
        if ($settings->getSignatureFilename() !== null) {
            $path = rtrim($this->signatureDir, '/\\').\DIRECTORY_SEPARATOR.$settings->getSignatureFilename();
            if (is_file($path)) {
                $mime = mime_content_type($path) ?: 'image/png';
                $signatureDataUri = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        $paymentType = PaymentType::tryFrom($membership->getPaymentType()) ?? PaymentType::CB;

        // Alloue un numéro d'ordre stable au premier rendu (aperçu ou envoi
        // email). Réutilisé pour tous les rendus suivants de la même
        // membership.
        if ($membership->getInvoiceSequence() === null) {
            $membership->setInvoiceSequence($this->memberships->nextInvoiceSequence($season));
            $this->em->flush();
        }
        $seasonLabel = $this->seasonLabel($season);
        $invoiceNumber = sprintf('TTM-%s-%02d', $seasonLabel, $membership->getInvoiceSequence());
        $isAttestation = $paymentType->isCollectedByFftri();

        $html = $this->twig->render('invoice/adherent.html.twig', [
            'settings' => $settings,
            'signatureDataUri' => $signatureDataUri,
            'bannerDataUri' => $this->bannerDataUri(),
            'user' => $user,
            'membership' => $membership,
            'season' => $season,
            'seasonLabel' => $seasonLabel,
            'fee' => $resolved['fee'],
            'profile' => $resolved['profile'],
            'typeLicence' => $resolved['typeLicence'],
            'paymentTypeLabel' => $paymentType->label(),
            'isAttestation' => $isAttestation,
            'invoiceNumber' => $invoiceNumber,
            'issuedAt' => new \DateTimeImmutable(),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /**
     * Nom de fichier suggéré pour un téléchargement. Préfixe adapté au
     * type de document réellement généré (attestation si la cotisation a
     * été encaissée par la FFTri, facture sinon — voir renderPdf()).
     */
    public function suggestedFilename(User $user, TrainingSeason $season): string
    {
        $membership = $this->memberships->findOneByUserAndSeason($user, $season);
        $paymentType = PaymentType::tryFrom($membership?->getPaymentType() ?? '') ?? PaymentType::CB;
        $prefix = $paymentType->isCollectedByFftri() ? 'attestation-paiement-' : 'facture-adhesion-';
        $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $user->getFullName().'-'.$this->seasonLabel($season));
        return $prefix.trim((string) $slug, '-').'.pdf';
    }

    /**
     * Facture famille : une seule facture au nom de l'adhérent principal,
     * avec plusieurs lignes (une par personne) et un montant par ligne
     * modifiable côté admin (permet d'appliquer une réduction famille).
     *
     * @param User            $primary   Adhérent au nom de qui la facture est émise.
     * @param list<array{
     *     user: User,
     *     amountCents: int,
     *     label?: string
     * }>                    $lines     Lignes à facturer.
     * @param TrainingSeason  $season    Saison concernée.
     */
    public function renderFamilyPdf(User $primary, array $lines, TrainingSeason $season, ?PaymentType $paymentType = null): string
    {
        if (!class_exists(Dompdf::class)) {
            throw new \RuntimeException(
                'La librairie dompdf/dompdf n\'est pas installée. Lancez : composer require dompdf/dompdf'
            );
        }
        if ($lines === []) {
            throw new \RuntimeException('Aucune ligne à facturer : sélectionnez au moins une personne.');
        }

        $settings = $this->settings->findCurrent();
        if ($settings === null) {
            throw new \RuntimeException('Paramètres facturation absents — configurez-les dans « Facturation → Paramètres ».');
        }

        $signatureDataUri = null;
        if ($settings->getSignatureFilename() !== null) {
            $path = rtrim($this->signatureDir, '/\\').\DIRECTORY_SEPARATOR.$settings->getSignatureFilename();
            if (is_file($path)) {
                $mime = mime_content_type($path) ?: 'image/png';
                $signatureDataUri = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        $seasonLabel = $this->seasonLabel($season);
        $isAttestation = $paymentType?->isCollectedByFftri() ?? false;
        $totalCents = 0;
        $serializedLines = [];
        foreach ($lines as $l) {
            $u = $l['user'];
            $amount = max(0, (int) $l['amountCents']);
            $totalCents += $amount;
            // Résout le typeLicence pour info (affiché sous la personne).
            $resolved = $this->resolveFee($u, $season);
            $serializedLines[] = [
                'user' => $u,
                'amountCents' => $amount,
                'label' => $l['label'] ?? null,
                'typeLicence' => $resolved['typeLicence'],
            ];
        }

        // Numéro dérivé d'un timestamp — la facture famille n'est pas
        // persistée dans invoice_sequence (celui-ci reste par adhérent).
        $invoiceNumber = sprintf('TTM-%s-FAM-%d', $seasonLabel, time());

        $html = $this->twig->render('invoice/family.html.twig', [
            'settings' => $settings,
            'signatureDataUri' => $signatureDataUri,
            'bannerDataUri' => $this->bannerDataUri(),
            'primary' => $primary,
            'lines' => $serializedLines,
            'totalCents' => $totalCents,
            'season' => $season,
            'seasonLabel' => $seasonLabel,
            'invoiceNumber' => $invoiceNumber,
            'issuedAt' => new \DateTimeImmutable(),
            'paymentTypeLabel' => $paymentType?->label(),
            'isAttestation' => $isAttestation,
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    public function suggestedFamilyFilename(User $primary, TrainingSeason $season, ?PaymentType $paymentType = null): string
    {
        $prefix = ($paymentType?->isCollectedByFftri() ?? false) ? 'attestation-paiement-famille-' : 'facture-famille-';
        $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $primary->getFullName().'-'.$this->seasonLabel($season));
        return $prefix.trim((string) $slug, '-').'.pdf';
    }

    /**
     * Tarif suggéré pour un user donné (montant en centimes ou null si
     * aucun tarif défini). Utilisé par le formulaire admin de facture
     * famille pour pré-remplir les montants avant édition.
     */
    public function suggestedAmountCents(User $user, TrainingSeason $season): ?int
    {
        $resolved = $this->resolveFee($user, $season);
        return $resolved['fee']?->getAmountCents();
    }

    /**
     * Charge une image bannière en data URI pour l'entête de la facture.
     * dompdf est offline (isRemoteEnabled=false), on doit donc lui
     * fournir les images en base64 inline. Ordre de recherche :
     *   1. Bannière ACTIVE configurée dans EasyAdmin (uploads/banners/{imagePath})
     *   2. Fallback img/banner-default.jpg
     * Retourne null si aucun fichier disponible → le template masque
     * simplement la balise <img>.
     */
    private function bannerDataUri(): ?string
    {
        $candidates = [];
        $active = $this->banners->findCurrentActive();
        if ($active !== null && $active->getImagePath() !== null) {
            $candidates[] = $this->publicDir.'/uploads/banners/'.$active->getImagePath();
        }
        $candidates[] = $this->publicDir.'/img/banner-default.jpg';

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $mime = mime_content_type($path) ?: 'image/jpeg';
                return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        }
        return null;
    }

    /**
     * Libellé humain de la saison (« 2026-2027 »), avec fallback :
     * - `season.name` s'il est renseigné (préféré)
     * - Sinon `startsAt.Y - endsAt.Y`
     * - Sinon l'id numérique (garantit toujours un identifiant).
     */
    private function seasonLabel(TrainingSeason $season): string
    {
        $name = $season->getName();
        if ($name !== null && trim($name) !== '') {
            return trim($name);
        }
        $starts = $season->getStartsAt();
        $ends = $season->getEndsAt();
        if ($starts !== null && $ends !== null) {
            return $starts->format('Y').'-'.$ends->format('Y');
        }
        return (string) $season->getId();
    }
}
