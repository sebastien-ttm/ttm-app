<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSeason;
use App\Entity\TrainingSlotTemplate;
use App\Enum\Sport;
use App\Repository\TrainingSeasonRepository;
use App\Repository\TrainingSlotTemplateRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class TrainingSlotTemplateCrudController extends AbstractCrudController
{
    private const DAY_CHOICES = [
        'Lundi' => 1,
        'Mardi' => 2,
        'Mercredi' => 3,
        'Jeudi' => 4,
        'Vendredi' => 5,
        'Samedi' => 6,
        'Dimanche' => 7,
    ];

    /** Valeurs spéciales pour le filtre saison. */
    private const SEASON_ACTIVE = 'active'; // vue par défaut : tout ce qui est encore en vigueur (cf. createIndexQueryBuilder)
    private const SEASON_ALL = 'all';       // toutes les saisons + sans saison
    private const SEASON_NONE = 'none';     // uniquement sans saison rattachée

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly TrainingSeasonRepository $seasons,
        private readonly TrainingSlotTemplateRepository $templates,
    ) {
    }

    /** @return array<string, Sport> */
    private static function sportChoices(): array
    {
        $out = [];
        foreach (Sport::adminChoices() as $c) {
            $out[$c->label()] = $c;
        }
        return $out;
    }

    public static function getEntityFqcn(): string
    {
        return TrainingSlotTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $help = match ($this->currentSeasonFilter()) {
            self::SEASON_ACTIVE => '✅ <strong>En cours</strong> — les créneaux de la saison courante ⭐ et tous ceux encore appliqués aux semaines à venir, '
                .'y compris ceux sans saison ou d\'une autre saison. Les créneaux dont la fin de validité est dépassée sont masqués : '
                .'retrouvez-les par saison ou avec « Toutes ».',
            self::SEASON_ALL => '📋 <strong>Toutes les saisons</strong> — historique complet, y compris les templates non rattachés.',
            self::SEASON_NONE => '❔ <strong>Sans saison</strong> — templates legacy à rattacher manuellement à une saison via le champ « Saison ».',
            default => '✅ Filtrage par saison. La saison qui contient aujourd\'hui est marquée ⭐ (fond bleu clair quand non sélectionnée).',
        };

        return $crud
            ->setEntityLabelInSingular('Créneau (semaine type)')
            ->setEntityLabelInPlural('Semaine type d\'entraînement')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['dayOfWeek' => 'ASC', 'startTime' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, $help);
    }

    public function configureActions(Actions $actions): Actions
    {
        $result = parent::configureActions($actions);
        $current = $this->currentSeasonFilter();

        // Repère la saison qui contient aujourd'hui pour la mettre en
        // évidence même quand elle n'est pas sélectionnée.
        $today = $this->seasons->findCurrent();
        $todayId = $today?->getId();

        // « En cours » : vue par défaut, ce qui est réellement projeté sur les semaines
        $btnActive = Action::new('seasonActive', '✅ En cours')
            ->linkToUrl($this->seasonUrl(self::SEASON_ACTIVE))
            ->setCssClass('btn '.($current === self::SEASON_ACTIVE ? 'btn-primary' : 'btn-secondary'))
            ->createAsGlobalAction();
        $result->add(Crud::PAGE_INDEX, $btnActive);

        // Un bouton par saison (triées récentes d'abord)
        $seasons = $this->seasons->createQueryBuilder('s')
            ->orderBy('s.startsAt', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($seasons as $s) {
            /** @var TrainingSeason $s */
            $id = (string) $s->getId();
            $isSelected = $current === $id;
            $isCurrent = $todayId !== null && $todayId === $s->getId();
            $label = $this->seasonLabel($s).($isCurrent ? ' ⭐' : '');

            // CSS : rouge (primary) si sélectionné, contour bleu si
            // saison courante non sélectionnée, gris sinon.
            $css = 'btn ';
            if ($isSelected) {
                $css .= 'btn-primary';
            } elseif ($isCurrent) {
                $css .= 'btn-info';
            } else {
                $css .= 'btn-secondary';
            }

            $btn = Action::new('season_'.$id, $label)
                ->linkToUrl($this->seasonUrl($id))
                ->setCssClass($css)
                ->createAsGlobalAction();
            $result->add(Crud::PAGE_INDEX, $btn);
        }

        // « Sans saison » : uniquement s'il en existe (évite de polluer sinon)
        $unassignedCount = (int) $this->templates->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.season IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
        if ($unassignedCount > 0) {
            $btnNone = Action::new('seasonNone', '❔ Sans saison ('.$unassignedCount.')')
                ->linkToUrl($this->seasonUrl(self::SEASON_NONE))
                ->setCssClass('btn '.($current === self::SEASON_NONE ? 'btn-primary' : 'btn-secondary'))
                ->createAsGlobalAction();
            $result->add(Crud::PAGE_INDEX, $btnNone);
        }

        // « Toutes »
        $btnAll = Action::new('seasonAll', '📋 Toutes')
            ->linkToUrl($this->seasonUrl(self::SEASON_ALL))
            ->setCssClass('btn '.($current === self::SEASON_ALL ? 'btn-primary' : 'btn-secondary'))
            ->createAsGlobalAction();
        $result->add(Crud::PAGE_INDEX, $btnAll);

        return $result;
    }

    /**
     * Filtre la requête d'index selon la saison sélectionnée.
     */
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $filter = $this->currentSeasonFilter();

        if ($filter === self::SEASON_ALL) {
            return $qb;
        }
        if ($filter === self::SEASON_ACTIVE) {
            // La projection des semaines (WeeklyScheduleService) ignore la saison du créneau : elle applique tout
            // créneau actif dont la fin de validité n'est pas dépassée. La vue par défaut doit donc montrer
            // ces créneaux-là (même sans saison ou d'une autre saison), sinon un créneau projeté chaque semaine
            // reste introuvable dans la semaine type — plus ceux de la saison courante (même terminés ou inactifs).
            $orX = $qb->expr()->orX('entity.endsAt IS NULL', 'entity.endsAt >= :today');
            $qb->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'));
            $current = $this->seasons->findCurrent();
            if ($current !== null) {
                $orX->add('entity.season = :currentSeason');
                $qb->setParameter('currentSeason', $current->getId());
            }

            return $qb->andWhere($orX);
        }
        if ($filter === self::SEASON_NONE) {
            return $qb->andWhere('entity.season IS NULL');
        }
        // Saison spécifique par id
        return $qb->andWhere('entity.season = :seasonId')
            ->setParameter('seasonId', (int) $filter);
    }

    /**
     * Nouveau créneau : rattaché par défaut à la saison courante (modifiable dans le formulaire). Sans cela, le
     * champ « Saison » restait vide et le créneau, sans saison, échappait aux vues filtrées par saison.
     */
    public function createEntity(string $entityFqcn): TrainingSlotTemplate
    {
        $template = new TrainingSlotTemplate();
        $template->setSeason($this->seasons->findCurrent());

        return $template;
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('season', 'Saison')
            ->autocomplete()
            ->setRequired(false)
            ->setHelp('Rattachement à une saison — sert de filtre principal. Laisser vide pour un créneau « permanent » (à éviter dans le nouveau modèle).');

        yield ChoiceField::new('dayOfWeek', 'Jour')
            ->setChoices(self::DAY_CHOICES)
            ->renderAsBadges();

        yield TimeField::new('startTime', 'Heure de début')
            ->setFormat('HH:mm')
            ->setFormTypeOption('input', 'datetime_immutable')
            ->setFormTypeOption('widget', 'single_text');

        yield IntegerField::new('durationMinutes', 'Durée (min)');

        yield ChoiceField::new('sport', 'Sport')
            ->setChoices(self::sportChoices())
            ->renderAsBadges();

        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Natation technique » ou « Vélo home-trainer »');

        yield TextField::new('location', 'Lieu')
            ->setHelp('Ex : « Piscine Léo-Lagrange » ou « Stade Daniel Faucher »');

        yield TextareaField::new('description', 'Description')
            ->hideOnIndex()
            ->setHelp('Optionnel. Précisions, niveau, matériel requis, etc.')
            ->setNumOfRows(3);

        yield BooleanField::new('isActive', 'Actif')
            ->setHelp('Décocher pour retirer ce créneau de la semaine type (sans le supprimer).');

        yield IntegerField::new('position', 'Position')
            ->hideOnIndex()
            ->setHelp('Optionnel : ordre d\'affichage à heure égale (0 par défaut).');

        yield DateField::new('startsAt', 'Début de validité')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Optionnel — pour sur-restreindre dans la saison (ex. PPG démarrant en janvier alors que la saison va de septembre à juin).');

        yield DateField::new('endsAt', 'Fin de validité')
            ->hideOnIndex()
            ->setRequired(false)
            ->setHelp('Optionnel — pour sur-restreindre dans la saison.');

        yield ChoiceField::new('audience', 'Audience cible')
            ->setChoices(\App\Enum\Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Si vide, visible par tous. Sinon, visible uniquement aux profils sélectionnés.');
    }

    // ==== Helpers ====

    /**
     * Renvoie le filtre saison courant, sous forme de string :
     *   - "all" | "none" | id numérique en chaîne
     * Défaut : "active" (« En cours » : saison courante + créneaux encore appliqués aux semaines à venir).
     */
    private function currentSeasonFilter(): string
    {
        $req = $this->requestStack->getCurrentRequest();
        $raw = $req?->query->get('seasonFilter');
        if ($raw === self::SEASON_ACTIVE || $raw === self::SEASON_ALL || $raw === self::SEASON_NONE) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            return $raw;
        }

        return self::SEASON_ACTIVE;
    }

    private function seasonUrl(string $filter): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('seasonFilter', $filter)
            ->generateUrl();
    }

    private function seasonLabel(TrainingSeason $s): string
    {
        // Utilise __toString() de l'entité : name si défini, sinon
        // fallback année-année, sinon #id.
        return (string) $s;
    }

}
