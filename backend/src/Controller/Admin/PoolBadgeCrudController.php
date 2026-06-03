<?php

namespace App\Controller\Admin;

use App\Entity\PoolBadge;
use App\Service\PdfToImageConverter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

class PoolBadgeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PdfToImageConverter $pdfConverter,
        private readonly string $poolBadgesDir,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PoolBadge::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $pdfNote = PdfToImageConverter::isAvailable()
            ? 'Si tu uploades un PDF, la 1re page sera automatiquement convertie en image (le QR sera affichable inline dans l\'app).'
            : 'Pour un affichage inline optimal sur l\'app, préfère une image (PNG/JPG) au PDF.';

        return $crud
            ->setEntityLabelInSingular('Badge piscines')
            ->setEntityLabelInPlural('Badge piscines')
            ->setEntityPermission('ROLE_ADMIN')
            ->setHelp(Crud::PAGE_INDEX,
                'Le QR code transmis chaque saison par la mairie pour l\'accès aux piscines. '
                .'Une seule entrée à la fois — remplace celle existante en réuploadant.')
            ->setHelp(Crud::PAGE_EDIT,
                'L\'image sera affichée plein écran sur l\'app mobile. '
                .'Formats acceptés : JPG, PNG, WebP, GIF, PDF. Max 5 Mo. '.$pdfNote);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Singleton : pas de suppression (on remplace l'image au lieu)
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex : « Badge saison 2025-2026 ». Affiché en haut du QR sur le mobile.');

        yield Field::new('file', 'Image du QR code (ou PDF)')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => false,
                'download_uri' => false,
                'image_uri' => true,
            ])
            ->onlyOnForms();

        yield ImageField::new('imagePath', 'Aperçu')
            ->setBasePath('/uploads/pool-badges')
            ->onlyOnIndex();

        yield TextareaField::new('notes', 'Notes')
            ->setRequired(false)
            ->setHelp('Optionnel. Texte court affiché sous le QR (ex : « À présenter à l\'accueil avec une pièce d\'identité »).')
            ->setNumOfRows(3);

        yield DateTimeField::new('updatedAt', 'Mis à jour le')->onlyOnIndex();
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        parent::persistEntity($em, $entityInstance);
        $this->maybeConvertPdfToImage($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        parent::updateEntity($em, $entityInstance);
        $this->maybeConvertPdfToImage($em, $entityInstance);
    }

    /**
     * Si le fichier qu'on vient de sauvegarder est un PDF et qu'Imagick
     * est dispo, on convertit la 1re page en PNG et on remplace dans
     * le badge. Sinon, on garde le PDF tel quel (rendu via iframe web
     * ou bouton "Ouvrir" natif).
     */
    private function maybeConvertPdfToImage(EntityManagerInterface $em, mixed $entity): void
    {
        if (!$entity instanceof PoolBadge) {
            return;
        }
        if ($entity->getMimeType() !== 'application/pdf' || !$entity->getImagePath()) {
            return;
        }

        $pdfPath = rtrim($this->poolBadgesDir, '/\\').\DIRECTORY_SEPARATOR.$entity->getImagePath();
        if (!is_file($pdfPath)) {
            return;
        }

        $newName = preg_replace('/\.pdf$/i', '.png', $entity->getImagePath()) ?? $entity->getImagePath();
        if ($newName === $entity->getImagePath()) {
            // L'extension n'était pas .pdf — on suffixe pour éviter d'écraser
            $newName = $entity->getImagePath().'.png';
        }
        $outputPath = rtrim($this->poolBadgesDir, '/\\').\DIRECTORY_SEPARATOR.$newName;

        if (!$this->pdfConverter->convert($pdfPath, $outputPath)) {
            $this->addFlash('warning',
                'Le PDF a été enregistré tel quel : la conversion automatique en image '
                .'n\'a pas pu être effectuée (Imagick / Ghostscript absent du serveur). '
                .'Pour un affichage inline parfait, convertis le PDF en PNG/JPG et réuploade.'
            );
            return;
        }

        // Conversion OK : on remplace le PDF par le PNG
        @unlink($pdfPath);
        $entity->setImagePath($newName);
        $entity->setMimeType('image/png');
        $em->flush();

        $this->addFlash('success', 'PDF converti en image automatiquement pour un affichage inline.');
    }
}
