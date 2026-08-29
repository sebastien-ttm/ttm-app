<?php

namespace App\Controller\Admin;

use App\Entity\TrainingSlotTemplate;
use App\Enum\Sport;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
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

    /**
     * Vues du bouton toggle en haut de la liste.
     * Valeurs de query param ?scope=…
     */
    private const SCOPE_CURRENT = 'current';   // par défaut : validité en cours
    private const SCOPE_ARCHIVED = 'archived'; // endsAt passé
    private const SCOPE_ALL = 'all';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    /**
     * @return array<string, Sport>
     */
    private static function sportChoices(): array
    {
        $out = [];
        foreach (Sport::cases() as $c) {
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
        $scope = $this->currentScope();
        $help = match ($scope) {
            self::SCOPE_ARCHIVED => '📦 <strong>Vue Archivés</strong> : créneaux dont la date de fin est passée. Ils ne s\'affichent plus dans le planning mais restent en base pour l\'historique.',
            self::SCOPE_ALL => '📋 <strong>Vue Tous</strong> : historique complet (actuels + à venir + archivés).',
            default => '✅ <strong>Vue Actuels</strong> : créneaux valides aujourd\'hui OU à venir (démarrage futur). Seuls les créneaux dont la date de fin est passée sont masqués.',
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
        // Boutons de bascule dans la barre d'action de l'index.
        $scope = $this->currentScope();

        $btnCurrent = Action::new('scopeCurrent', '✅ Actuels', null)
            ->linkToUrl($this->scopeUrl(self::SCOPE_CURRENT))
            ->setCssClass('btn '.($scope === self::SCOPE_CURRENT ? 'btn-primary' : 'btn-secondary'))
            ->createAsGlobalAction();
        $btnArchived = Action::new('scopeArchived', '📦 Archivés', null)
            ->linkToUrl($this->scopeUrl(self::SCOPE_ARCHIVED))
            ->setCssClass('btn '.($scope === self::SCOPE_ARCHIVED ? 'btn-primary' : 'btn-secondary'))
            ->createAsGlobalAction();
        $btnAll = Action::new('scopeAll', '📋 Tous', null)
            ->linkToUrl($this->scopeUrl(self::SCOPE_ALL))
            ->setCssClass('btn '.($scope === self::SCOPE_ALL ? 'btn-primary' : 'btn-secondary'))
            ->createAsGlobalAction();

        return parent::configureActions($actions)
            ->add(Crud::PAGE_INDEX, $btnCurrent)
            ->add(Crud::PAGE_INDEX, $btnArchived)
            ->add(Crud::PAGE_INDEX, $btnAll);
    }

    /**
     * Filtre la requête d'index selon le scope courant.
     */
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        switch ($this->currentScope()) {
            case self::SCOPE_ARCHIVED:
                $qb->andWhere('entity.endsAt IS NOT NULL AND entity.endsAt < :today')
                    ->setParameter('today', $today);
                break;
            case self::SCOPE_ALL:
                // Aucun filtre
                break;
            case self::SCOPE_CURRENT:
            default:
                // « Actuels » = tout ce qui n'est pas archivé : validité
                // en cours OU à venir (startsAt futur). Pas de restriction
                // sur startsAt — l'admin doit voir/gérer les créneaux
                // préparés pour la saison à venir avant qu'elle démarre.
                $qb->andWhere('entity.endsAt IS NULL OR entity.endsAt >= :today')
                    ->setParameter('today', $today);
                break;
        }
        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
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

        // Startsat / endsAt visibles sur index dans les vues archivés/tous
        // (essentiels pour comprendre pourquoi un créneau est archivé)
        $showDatesOnIndex = in_array($this->currentScope(), [self::SCOPE_ARCHIVED, self::SCOPE_ALL], true);

        $startsAt = DateField::new('startsAt', 'Début de validité')
            ->setRequired(false)
            ->setHelp('Si défini, ce créneau ne s\'applique qu\'à partir de cette date (ex. PPG démarrant en janvier). Laisser vide = toute la saison.');
        yield $showDatesOnIndex ? $startsAt : $startsAt->hideOnIndex();

        $endsAt = DateField::new('endsAt', 'Fin de validité')
            ->setRequired(false)
            ->setHelp('Si défini, ce créneau ne s\'applique que jusqu\'à cette date (inclus). Une date passée = créneau archivé.');
        yield $showDatesOnIndex ? $endsAt : $endsAt->hideOnIndex();

        yield ChoiceField::new('audience', 'Audience cible')
            ->setChoices(\App\Enum\Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Si vide, visible par tous. Sinon, visible uniquement aux profils sélectionnés.');
    }

    // ==== Helpers ====

    private function currentScope(): string
    {
        $req = $this->requestStack->getCurrentRequest();
        $scope = $req?->query->get('scope', self::SCOPE_CURRENT);
        return in_array($scope, [self::SCOPE_CURRENT, self::SCOPE_ARCHIVED, self::SCOPE_ALL], true)
            ? $scope
            : self::SCOPE_CURRENT;
    }

    private function scopeUrl(string $scope): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set('scope', $scope)
            ->generateUrl();
    }
}
