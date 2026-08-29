<?php

namespace App\Controller\Admin;

use App\Entity\ClubCharter;
use App\Repository\ClubCharterRepository;
use App\Service\Charter\FormSchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ClubCharterCrudController extends AbstractCrudController
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
        private readonly ClubCharterRepository $charters,
        private readonly FormSchemaValidator $formValidator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ClubCharter::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Charte')
            ->setEntityLabelInPlural('Chartes du club')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Charte de l\'adhérent — Saison 2026 »');
        yield TextField::new('version', 'Version / Saison')
            ->setHelp('Identifiant lisible, ex : « 2026 » ou « 2026-rev2 »');
        yield TextEditorField::new('content', 'Contenu')
            ->setHelp('Texte intégral présenté à l\'adhérent. L\'éditeur supporte les images, listes, mise en forme.')
            ->onlyOnForms();

        yield TextareaField::new('fieldsJson', 'Champs du formulaire')
            ->onlyOnForms()
            ->setNumOfRows(20)
            ->setFormTypeOption('attr', [
                // data-charter-builder → activé par js/admin/charter-form-builder.js :
                // cache cette textarea, monte un builder visuel au-dessus, sync
                // bidirectionnelle. Bouton « Éditer le JSON brut » réactive
                // la textarea pour édition manuelle.
                'data-charter-builder' => 'true',
                'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; white-space: pre;',
                'spellcheck' => 'false',
                'placeholder' => self::FIELDS_TEMPLATE,
            ])
            ->setHelp(
                'Utilisez le builder ci-dessous pour construire le formulaire visuellement,'
                .' ou basculez en mode JSON avancé si besoin.'
                .' Laisser vide pour une charte « simple bouton J\'accepte ».'
            );

        yield BooleanField::new('isActive', 'Active')
            ->setHelp('Activer cette charte la rend obligatoire pour tous les utilisateurs et désactive automatiquement les autres.');

        yield AssociationField::new('previewUser', 'Aperçu privé')
            ->autocomplete()
            ->setRequired(false)
            ->setHelp(
                'Optionnel. Si renseigné, la charte est visible uniquement par cet utilisateur '
                .'(pratique pour tester le contenu et le formulaire avant d\'activer pour tout '
                .'le monde). Ignoré dès que « Active » est coché.'
            );

        yield DateTimeField::new('publishedAt', 'Publiée le')->onlyOnIndex();
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->normalizeSchema($entityInstance);
        $this->validateSchema($entityInstance);
        $this->ensureSingleActive($em, $entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->normalizeSchema($entityInstance);
        $this->validateSchema($entityInstance);
        $this->ensureSingleActive($em, $entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    /**
     * Nettoie chaque champ pour se conformer au format « charte simplifiée » :
     *  - type forcé à 'checkbox' (seul type supporté désormais)
     *  - required forcé à true (tous les engagements bloquants)
     *  - help/options supprimés
     *
     * Rend les schémas legacy conformes automatiquement au prochain save,
     * en miroir de ce que fait le form builder JS côté rendu.
     */
    private function normalizeSchema(mixed $entity): void
    {
        if (!$entity instanceof ClubCharter) {
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
        if (!$entity instanceof ClubCharter) {
            return;
        }
        $errors = $this->formValidator->validateSchema($entity->getFields());
        if ($errors !== []) {
            // EasyAdmin n'affiche pas joliment un throw, mais l'exception
            // remonte un message clair dans la page "Erreur" ; ça évite
            // surtout d'enregistrer un schéma corrompu.
            throw new \RuntimeException("Schéma de formulaire invalide :\n- ".implode("\n- ", $errors));
        }
    }

    private function ensureSingleActive(EntityManagerInterface $em, $entity): void
    {
        if (!$entity instanceof ClubCharter || !$entity->isActive()) {
            return;
        }
        // Désactive toutes les autres chartes (sauf celle-ci si elle a un id)
        $this->charters->deactivateAllExcept($entity->getId());
    }
}
