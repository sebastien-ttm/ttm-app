<?php

namespace App\Controller\Admin;

use App\Entity\CharterEngagementSettings;
use App\Repository\CharterEngagementSettingsRepository;
use App\Service\Charter\FormSchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * CRUD singleton pour les engagements du formulaire d'acceptation.
 * Édition seule (pas de new / delete). Index redirige vers l'édition
 * de la ligne unique (créée par la migration, ou à la volée).
 */
class CharterEngagementSettingsCrudController extends AbstractCrudController
{
    private const FIELDS_TEMPLATE = <<<'JSON'
[
  {
    "id": "engagement_horaires",
    "label": "Je m'engage à respecter les horaires d'entraînement",
    "type": "checkbox",
    "required": true,
    "description": "En arrivant à l'heure et en prévenant en cas d'absence, je permets au groupe de démarrer ensemble et je contribue à la cohésion collective."
  },
  {
    "id": "engagement_materiel",
    "label": "Je m'engage à respecter le matériel du club",
    "type": "checkbox",
    "required": true,
    "description": "Le matériel prêté est fragile et coûteux : je le manipule avec soin, le range à sa place et signale toute dégradation à un encadrant."
  }
]
JSON;

    public function __construct(
        private readonly CharterEngagementSettingsRepository $repo,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly EntityManagerInterface $em,
        private readonly FormSchemaValidator $formValidator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return CharterEngagementSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Engagements')
            ->setEntityLabelInPlural('Engagements')
            ->setEntityPermission('ROLE_ADMIN')
            ->setPageTitle('edit', '✅ Engagements du formulaire d\'acceptation')
            ->setHelp('edit',
                'Ces engagements sont présentés à <strong>tous les adhérents</strong> '
                .'(nouveaux comme renouvellements). La spécificité par profil '
                .'(Parent/Jeune vs Sénior) est portée par la case « Audience » '
                .'de chaque engagement.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::DELETE, Action::NEW, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextareaField::new('fieldsJson', 'Engagements')
            ->setNumOfRows(20)
            ->setFormTypeOption('attr', [
                'data-charter-builder' => 'true',
                'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; white-space: pre;',
                'spellcheck' => 'false',
                'placeholder' => self::FIELDS_TEMPLATE,
            ])
            ->setHelp(
                'Utilisez le builder pour construire visuellement la liste, '
                .'ou basculez en mode JSON avancé si besoin.'
            );
    }

    public function index(AdminContext $context)
    {
        $settings = $this->repo->findCurrent();
        if ($settings === null) {
            $settings = new CharterEngagementSettings();
            $this->em->persist($settings);
            $this->em->flush();
        }
        return new RedirectResponse(
            $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::EDIT)
                ->setEntityId($settings->getId())
                ->generateUrl(),
        );
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->normalizeSchema($entityInstance);
        $this->validateSchema($entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->normalizeSchema($entityInstance);
        $this->validateSchema($entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    /**
     * Nettoie chaque champ : type forcé à 'checkbox', required forcé à true,
     * help/options supprimés (miroir du builder JS côté rendu).
     */
    private function normalizeSchema(mixed $entity): void
    {
        if (!$entity instanceof CharterEngagementSettings) {
            return;
        }
        $fields = $entity->getFields();
        if ($fields === null || $fields === []) {
            return;
        }
        $normalized = [];
        foreach ($fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            unset($f['help'], $f['options']);
            $f['type'] = 'checkbox';
            $f['required'] = true;
            $normalized[] = $f;
        }
        $entity->setFields($normalized);
    }

    private function validateSchema(mixed $entity): void
    {
        if (!$entity instanceof CharterEngagementSettings) {
            return;
        }
        $errors = $this->formValidator->validateSchema($entity->getFields());
        if ($errors !== []) {
            throw new \RuntimeException("Schéma d'engagements invalide :\n- ".implode("\n- ", $errors));
        }
    }
}
