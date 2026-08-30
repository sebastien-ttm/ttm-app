<?php

namespace App\Controller\Admin;

use App\Entity\ClubCharter;
use App\Service\Charter\FormSchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
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
            ->setEntityLabelInSingular('Formulaire d\'acceptation')
            ->setEntityLabelInPlural('Formulaires d\'acceptation')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Formulaire d\'acceptation — Saison 2026-2027 »');
        yield TextField::new('version', 'Version / Saison')
            ->setHelp('Identifiant lisible, ex : « 2026-2027 » ou « 2026-2027-rev2 »');
        yield TextEditorField::new('content', 'Contenu')
            ->setHelp('Texte intégral présenté à l\'adhérent. L\'éditeur supporte les images, listes, mise en forme.')
            ->onlyOnForms();

        yield TextareaField::new('fieldsJson', 'Engagements')
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
                'Utilisez le builder ci-dessous pour construire la liste d\'engagements '
                .'visuellement, ou basculez en mode JSON avancé si besoin. '
                .'Laisser vide pour un formulaire « simple bouton J\'accepte ».'
            );

        yield AssociationField::new('previewUser', 'Aperçu privé')
            ->autocomplete()
            ->setRequired(false)
            ->setHelp(
                'Optionnel. Si renseigné, ce formulaire est visible uniquement '
                .'par cet utilisateur — pratique pour valider le contenu et les '
                .'engagements sans impacter les autres adhérents. Le formulaire '
                .'le plus récemment publié est présenté à tous les autres.'
            );

        yield DateTimeField::new('publishedAt', 'Publié le')->onlyOnIndex();
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
     * Nettoie chaque champ pour se conformer au format simplifié :
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

}
