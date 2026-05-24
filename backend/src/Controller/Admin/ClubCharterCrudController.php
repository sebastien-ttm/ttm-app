<?php

namespace App\Controller\Admin;

use App\Entity\ClubCharter;
use App\Repository\ClubCharterRepository;
use App\Service\Charter\FormSchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
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
    "id": "size",
    "label": "Taille de t-shirt",
    "type": "select",
    "required": true,
    "options": ["XS", "S", "M", "L", "XL", "XXL"]
  },
  {
    "id": "emergency_contact",
    "label": "Personne à prévenir en cas d'urgence (nom + téléphone)",
    "type": "text",
    "required": true
  },
  {
    "id": "newsletter",
    "label": "J'accepte de recevoir la newsletter du club",
    "type": "checkbox",
    "required": false
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

        yield TextareaField::new('fieldsJson', 'Champs du formulaire (JSON)')
            ->onlyOnForms()
            ->setNumOfRows(20)
            ->setFormTypeOption('attr', [
                'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; white-space: pre;',
                'spellcheck' => 'false',
                'placeholder' => self::FIELDS_TEMPLATE,
            ])
            ->setHelp(
                'Optionnel. Laisser vide pour une charte "simple bouton J\'accepte".'
                .' Sinon, fournir un tableau JSON décrivant les questions :'
                .' chaque élément doit avoir <code>id</code> (slug : minuscules, chiffres, _),'
                .' <code>label</code>, <code>type</code> (text, textarea, number, date, checkbox, select, radio),'
                .' optionnellement <code>required</code>, <code>help</code>,'
                .' et <code>options</code> (liste) pour select/radio.'
                .' Voir le placeholder du champ pour un exemple complet.'
            );

        yield BooleanField::new('isActive', 'Active')
            ->setHelp('Activer cette charte la rend obligatoire pour tous les utilisateurs et désactive automatiquement les autres.');
        yield DateTimeField::new('publishedAt', 'Publiée le')->onlyOnIndex();
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->validateSchema($entityInstance);
        $this->ensureSingleActive($em, $entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->validateSchema($entityInstance);
        $this->ensureSingleActive($em, $entityInstance);
        parent::updateEntity($em, $entityInstance);
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
