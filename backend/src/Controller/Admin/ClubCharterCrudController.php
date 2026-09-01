<?php

namespace App\Controller\Admin;

use App\Entity\ClubCharter;
use App\Service\Charter\FormSchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD unique du tunnel d'onboarding (par saison / version) :
 *   1) Message de bienvenue (contenu HTML)
 *   2) Engagements — 1 case à cocher par écran du tunnel (JSON via builder)
 *   3) Message final avant validation d'accès (contenu HTML)
 *
 * Un seul menu, une seule fiche : l'admin configure tout en un endroit.
 * Le tunnel actif = la fiche la plus récente publiée (previewUser prime
 * pour l'aperçu privé). Les versions plus anciennes restent visibles
 * dans la liste pour historique.
 */
class ClubCharterCrudController extends AbstractCrudController
{
    private const FIELDS_TEMPLATE = <<<'JSON'
[
  {
    "id": "engagement_horaires",
    "title": "Horaires",
    "label": "Je m'engage à respecter les horaires d'entraînement",
    "type": "checkbox",
    "required": true,
    "description": "En arrivant à l'heure et en prévenant en cas d'absence, je permets au groupe de démarrer ensemble et je contribue à la cohésion collective."
  },
  {
    "id": "engagement_materiel",
    "title": "Matériel",
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
            ->setEntityLabelInSingular('Message de bienvenue')
            ->setEntityLabelInPlural('Messages de bienvenue')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->setHelp('index',
                'Le tunnel d\'onboarding actif est le plus récent publié — '
                .'tous les adhérents (nouveaux comme renouvellements) voient '
                .'le même. La liste conserve l\'historique par saison. '
                .'Chaque fiche configure les trois écrans du tunnel : '
                .'message de bienvenue, engagements (un par écran), '
                .'message final avant validation d\'accès.',
            );
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Message de bienvenue — Saison 2026-2027 »');
        yield TextField::new('version', 'Version / Saison')
            ->setHelp('Identifiant lisible, ex : « 2026-2027 » ou « 2026-2027-rev2 »');

        yield FormField::addPanel('Écran 1 — Message de bienvenue')->onlyOnForms();
        yield TextEditorField::new('content', 'Contenu')
            ->setHelp('Texte présenté sur le premier écran du tunnel. L\'éditeur supporte les images, listes, mise en forme, boutons.')
            ->onlyOnForms();

        yield FormField::addPanel('Écrans intermédiaires — Engagements')->onlyOnForms();
        yield TextareaField::new('fieldsJson', 'Engagements')
            ->onlyOnForms()
            ->setNumOfRows(20)
            ->setFormTypeOption('attr', [
                'data-charter-builder' => 'true',
                'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; white-space: pre;',
                'spellcheck' => 'false',
                'placeholder' => self::FIELDS_TEMPLATE,
            ])
            ->setHelp(
                'Un engagement = un écran du tunnel. L\'adhérent coche pour passer '
                .'au suivant. Utilisez le builder ci-dessous ou basculez en JSON avancé.'
            );

        yield FormField::addPanel('Écran final — Validation d\'accès')->onlyOnForms();
        yield TextEditorField::new('finalMessage', 'Message final')
            ->setRequired(false)
            ->setHelp('Affiché sur le dernier écran du tunnel, juste avant le bouton « Valider mon accès à l\'application ». Laisser vide pour sauter cet écran.')
            ->onlyOnForms();

        yield AssociationField::new('previewUser', 'Aperçu privé')
            ->autocomplete()
            ->setRequired(false)
            ->setHelp(
                'Optionnel. Si renseigné, ce tunnel est visible uniquement par '
                .'cet utilisateur — pratique pour valider le contenu sans '
                .'impacter les autres adhérents.'
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
     * Nettoie chaque engagement : type forcé à 'checkbox', required forcé
     * à true, help/options supprimés (miroir du builder JS côté rendu).
     */
    private function normalizeSchema(mixed $entity): void
    {
        if (!$entity instanceof ClubCharter) return;
        $fields = $entity->getFields();
        if ($fields === null || $fields === []) return;
        $normalized = [];
        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            unset($f['help'], $f['options']);
            $f['type'] = 'checkbox';
            $f['required'] = true;
            $normalized[] = $f;
        }
        $entity->setFields($normalized);
    }

    private function validateSchema(mixed $entity): void
    {
        if (!$entity instanceof ClubCharter) return;
        $errors = $this->formValidator->validateSchema($entity->getFields());
        if ($errors !== []) {
            throw new \RuntimeException("Schéma d'engagements invalide :\n- ".implode("\n- ", $errors));
        }
    }
}
