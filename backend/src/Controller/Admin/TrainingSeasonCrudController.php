<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSeason;
use App\Repository\TrainingSlotTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TrainingSeasonCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TrainingSlotTemplateRepository $templates,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TrainingSeason::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Saison d\'entraînement')
            ->setEntityLabelInPlural('Saison d\'entraînement')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setHelp(Crud::PAGE_INDEX,
                'Définit la période sur laquelle la <strong>semaine type</strong> est appliquée. '
                .'En dehors de cette période (par ex. l\'été), aucun créneau récurrent n\'est affiché — '
                .'mais on peut toujours ajouter des créneaux occasionnels pour les vacances.')
            ->setHelp(Crud::PAGE_EDIT,
                'Laisser les deux dates vides = la semaine type s\'applique toute l\'année.');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Action « Cloner la semaine type ici » : borne les créneaux
        // actifs actuels à la veille du startsAt de cette saison et crée
        // leurs copies attachées à la nouvelle saison. Voir cloneTemplates()
        // pour la logique complète.
        $cloneTemplates = Action::new('cloneTemplates', 'Cloner semaine type ici', 'fa fa-clone')
            ->linkToCrudAction('cloneTemplates')
            ->setCssClass('btn btn-primary')
            ->displayIf(fn (TrainingSeason $s) => $s->getStartsAt() !== null);

        return $actions
            ->disable(Action::DELETE)
            ->add(Crud::PAGE_INDEX, $cloneTemplates)
            ->add(Crud::PAGE_DETAIL, $cloneTemplates);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateField::new('startsAt', 'Début de saison')
            ->setRequired(false)
            ->setHelp('Date de début (incluse). Ex. 25 août pour une saison qui démarre fin août.');
        yield DateField::new('endsAt', 'Fin de saison')
            ->setRequired(false)
            ->setHelp('Date de fin (incluse). Ex. 5 juillet pour une saison qui se termine début juillet.');
    }

    /**
     * Clone tous les créneaux de la semaine type actuellement actifs
     * vers cette saison, en préservant l'historique :
     *
     *  1. Pour chaque template actif dont la plage inclut ENCORE le début
     *     de la nouvelle saison (endsAt null ou >= startsAt), pose
     *     endsAt = veille du startsAt (fige la version « ancienne saison »).
     *  2. Crée une copie de ce template avec startsAt = début de la
     *     nouvelle saison, endsAt = null.
     *
     * Idempotent : refuse si des templates démarrent déjà pile au
     * startsAt de la saison (probable double-clic).
     */
    public function cloneTemplates(AdminContext $context): RedirectResponse
    {
        /** @var TrainingSeason $season */
        $season = $context->getEntity()->getInstance();

        $seasonStart = $season->getStartsAt();
        if ($seasonStart === null) {
            $this->addFlash('warning', 'La saison doit avoir une date de début pour cloner les créneaux.');
            return $this->redirectToIndex();
        }

        // Garde-fou : évite le double clonage sur la même saison
        $alreadyCloned = $this->templates->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.startsAt = :d')
            ->setParameter('d', $seasonStart->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();
        if ((int) $alreadyCloned > 0) {
            $this->addFlash('warning', sprintf(
                'Le clonage a déjà eu lieu (%d créneau(x) démarrent déjà le %s). Action ignorée.',
                (int) $alreadyCloned,
                $seasonStart->format('d/m/Y'),
            ));
            return $this->redirectToIndex();
        }

        $dayBeforeStart = $seasonStart->modify('-1 day');
        $sourceLabel = 'encore actifs';

        // Étape 1 : templates encore vivants au début de la nouvelle saison
        // (endsAt null ou postérieur). Cas nominal : on repart des créneaux
        // « courants », on les fige puis on les duplique.
        $sourceTemplates = $this->templates->createQueryBuilder('t')
            ->where('t.isActive = true')
            ->andWhere('t.endsAt IS NULL OR t.endsAt >= :seasonStart')
            ->setParameter('seasonStart', $seasonStart->format('Y-m-d'))
            ->orderBy('t.dayOfWeek', 'ASC')
            ->addOrderBy('t.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        // Étape 2 (fallback) : si aucun créneau vivant, on ratisse TOUS les
        // templates archivés avant la nouvelle saison — indépendamment de
        // leur endsAt exact. Puis on déduplique par « identité » (jour +
        // heure + sport + titre) en gardant la version la plus récente de
        // chacun. Ça évite de cloner en double si un créneau a été
        // archivé plusieurs fois au fil des saisons, mais couvre le cas où
        // différents créneaux ont été archivés à des dates différentes
        // (retraits ponctuels en cours de saison, etc.).
        if (count($sourceTemplates) === 0) {
            $archived = $this->templates->createQueryBuilder('t')
                ->where('t.isActive = true')
                ->andWhere('t.endsAt IS NOT NULL')
                ->andWhere('t.endsAt < :seasonStart')
                ->setParameter('seasonStart', $seasonStart->format('Y-m-d'))
                ->orderBy('t.endsAt', 'DESC')  // plus récent d'abord
                ->addOrderBy('t.dayOfWeek', 'ASC')
                ->addOrderBy('t.startTime', 'ASC')
                ->getQuery()
                ->getResult();

            $latestByIdentity = [];
            foreach ($archived as $t) {
                $key = $t->getDayOfWeek()
                    .'|'.$t->getStartTime()->format('H:i:s')
                    .'|'.$t->getSport()->value
                    .'|'.$t->getTitle();
                // Premier vu = le plus récent (tri endsAt DESC), on ignore les suivants
                if (!isset($latestByIdentity[$key])) {
                    $latestByIdentity[$key] = $t;
                }
            }
            $sourceTemplates = array_values($latestByIdentity);
            if ($sourceTemplates !== []) {
                $sourceLabel = 'issus des saisons précédentes (dédupliqués par créneau)';
            }
        }

        if (count($sourceTemplates) === 0) {
            $this->addFlash('info', 'Aucun créneau à cloner. Créez d\'abord des créneaux dans la semaine type.');
            return $this->redirectToIndex();
        }

        $cloned = 0;
        foreach ($sourceTemplates as $old) {
            // On ne re-fige que les templates encore vivants ; ceux déjà
            // archivés (fallback) gardent leur endsAt d'origine — sinon
            // on écraserait la date de fin de la saison précédente.
            if ($old->getEndsAt() === null || $old->getEndsAt() >= $seasonStart) {
                $old->setEndsAt($dayBeforeStart);
            }
            $new = $old->duplicateForRange($seasonStart, null);
            $this->em->persist($new);
            $cloned++;
        }
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%d créneau(x) clonés (%s) pour la saison démarrant le %s. Ajustez les nouveaux librement — les anciens gardent leur historique.',
            $cloned,
            $sourceLabel,
            $seasonStart->format('d/m/Y'),
        ));

        return $this->redirectToIndex();
    }

    private function redirectToIndex(): RedirectResponse
    {
        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
        return $this->redirect($url);
    }
}
