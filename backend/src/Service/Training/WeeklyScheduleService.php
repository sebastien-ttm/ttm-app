<?php

namespace App\Service\Training;

use App\Entity\TrainingSlot;
use App\Entity\TrainingSlotTemplate;
use App\Entity\User;
use App\Repository\TrainingSeasonRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\TrainingSlotTemplateRepository;
use App\Service\Audience\AudienceFilter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Construit la vue d'une semaine d'entraînement en fusionnant la
 * "semaine type" (templates) avec les overrides de la semaine ciblée.
 *
 * Règles :
 *  - Si un TrainingSlot existe pour (week, template) → il REMPLACE le template.
 *  - Sinon, le template est rendu tel quel (virtuel, pas persisté).
 *  - Les TrainingSlot avec template=null (occasionnels) s'ajoutent.
 *
 * Les lectures sont sans side-effects ; la matérialisation ne se produit
 * QUE lors d'une édition par l'admin (méthode materializeOverride).
 */
class WeeklyScheduleService
{
    public function __construct(
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly TrainingSlotRepository $slots,
        private readonly TrainingSeasonRepository $seasons,
        private readonly AudienceFilter $audienceFilter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function snapToMonday(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return $d->modify('monday this week')->setTime(0, 0, 0);
    }

    /**
     * Vue normalisée d'une semaine, prête à être sérialisée en JSON.
     *
     * @return list<array<string, mixed>>  Liste ordonnée (jour, heure)
     */
    public function buildWeek(\DateTimeImmutable $weekStartsAt, ?User $viewer = null): array
    {
        $monday = self::snapToMonday($weekStartsAt);

        $overrides = $this->slots->findForWeek($monday);
        $overridesByTemplate = [];
        $occasionals = [];
        foreach ($overrides as $s) {
            $tpl = $s->getTemplate();
            if ($tpl !== null && $tpl->getId() !== null) {
                $overridesByTemplate[$tpl->getId()] = $s;
            } else {
                $occasionals[] = $s;
            }
        }

        // Filtre global saison : si la semaine n'est pas dans la saison,
        // on ne projette PAS les templates (mais on garde les occasionnels
        // qui ont été ajoutés explicitement pour cette semaine).
        $season = $this->seasons->findCurrent();
        $weekInSeason = $season === null || $season->contains($monday);

        $rows = [];

        // 1) Templates actifs (override si présent, sinon virtuel)
        if ($weekInSeason) {
            foreach ($this->templates->findActiveOrdered() as $tpl) {
                // Filtre dates par créneau
                if (!$tpl->appliesOn($monday)) {
                    continue;
                }
                $override = $overridesByTemplate[$tpl->getId()] ?? null;
                // Filtre audience : si l'override a une audience perso, elle prime ;
                // sinon on prend celle du template. Encadrant/Entraîneur bypass —
                // ils voient tous les créneaux (vision staff / supervision).
                $effectiveAudience = $override?->getAudience() ?: $tpl->getAudience();
                if ($viewer !== null && !$this->audienceFilter->isSlotVisible($effectiveAudience, $viewer)) {
                    continue;
                }
                if ($override !== null) {
                    $rows[] = $this->serializeOverride($override, $monday);
                } else {
                    $rows[] = $this->serializeVirtual($tpl, $monday);
                }
            }
        }

        // 2) Créneaux occasionnels (sans template) — même règle (staff = bypass)
        foreach ($occasionals as $s) {
            if ($viewer !== null && !$this->audienceFilter->isSlotVisible($s->getAudience(), $viewer)) {
                continue;
            }
            $rows[] = $this->serializeOverride($s, $monday);
        }

        // Tri final (jour, heure)
        usort($rows, function (array $a, array $b): int {
            return [$a['dayOfWeek'], $a['startTime']] <=> [$b['dayOfWeek'], $b['startTime']];
        });

        return $rows;
    }

    /**
     * Matérialise (= crée un TrainingSlot pour la semaine ciblée) à partir
     * d'un template, si ce n'est pas déjà fait. Renvoie l'instance (override).
     */
    public function materializeOverride(
        \DateTimeImmutable $weekStartsAt,
        TrainingSlotTemplate $template,
    ): TrainingSlot {
        $monday = self::snapToMonday($weekStartsAt);

        $existing = $this->slots->findOverride($monday, $template);
        if ($existing !== null) {
            return $existing;
        }

        $slot = (new TrainingSlot())
            ->setWeekStartsAt($monday)
            ->fillFromTemplate($template);
        $this->em->persist($slot);
        // Flush au moment où l'appelant le décide.
        return $slot;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeVirtual(TrainingSlotTemplate $tpl, \DateTimeImmutable $monday): array
    {
        $date = $monday->modify(sprintf('+%d days', $tpl->getDayOfWeek() - 1));
        return [
            'id' => null,                   // créneau virtuel (pas encore matérialisé)
            'templateId' => $tpl->getId(),
            'date' => $date->format('Y-m-d'),
            'dayOfWeek' => $tpl->getDayOfWeek(),
            'startTime' => $tpl->getStartTime()->format('H:i'),
            'durationMinutes' => $tpl->getDurationMinutes(),
            'sport' => $tpl->getSport()->value,
            'sportLabel' => $tpl->getSport()->label(),
            'sportIcon' => $tpl->getSport()->icon(),
            'sportColor' => $tpl->getSport()->color(),
            'title' => $tpl->getTitle(),
            'location' => $tpl->getLocation(),
            'description' => $tpl->getDescription(),
            'isCancelled' => false,
            'isOverride' => false,
            'isOccasional' => false,
            'attachments' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOverride(TrainingSlot $s, \DateTimeImmutable $monday): array
    {
        $date = $monday->modify(sprintf('+%d days', $s->getDayOfWeek() - 1));
        $tpl = $s->getTemplate();
        return [
            'id' => $s->getId(),
            'templateId' => $tpl?->getId(),
            'date' => $date->format('Y-m-d'),
            'dayOfWeek' => $s->getDayOfWeek(),
            'startTime' => $s->getStartTime()->format('H:i'),
            'durationMinutes' => $s->getDurationMinutes(),
            'sport' => $s->getSport()->value,
            'sportLabel' => $s->getSport()->label(),
            'sportIcon' => $s->getSport()->icon(),
            'sportColor' => $s->getSport()->color(),
            'title' => $s->getTitle(),
            'location' => $s->getLocation(),
            'description' => $s->getDescription(),
            'isCancelled' => $s->isCancelled(),
            // « Modifié » : le slot existe en BDD ET diffère matériellement
            // du template. Un override créé uniquement pour porter une
            // présence staff ou une pièce jointe ne passe pas en true.
            'isOverride' => $tpl !== null && $s->differsMateriallyFromTemplate(),
            'isOccasional' => $tpl === null,
            'attachments' => array_map(
                fn ($att) => [
                    'id' => $att->getId(),
                    'name' => $att->getOriginalName(),
                    'size' => $att->getSize(),
                    'humanSize' => $att->getHumanSize(),
                    'mimeType' => $att->getMimeType(),
                ],
                $s->getAttachments()->toArray(),
            ),
        ];
    }
}
