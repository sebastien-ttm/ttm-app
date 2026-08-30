<?php

namespace App\Controller\Admin;

use App\Entity\InvoiceSettings;
use App\Repository\InvoiceSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * CRUD singleton pour InvoiceSettings — édition seule (pas de new /
 * delete). Index redirige vers l'édition de la ligne unique (seed créée
 * par la migration).
 */
class InvoiceSettingsCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly InvoiceSettingsRepository $repo,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly string $signatureDir,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return InvoiceSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Paramètres facturation')
            ->setEntityLabelInPlural('Paramètres facturation')
            ->setEntityPermission('ROLE_ADMIN')
            ->setPageTitle('edit', '📄 Paramètres facturation');
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::DELETE, Action::NEW, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('clubName', 'Nom du club');
        yield TextareaField::new('clubAddress', 'Adresse du club')
            ->setNumOfRows(4)
            ->setHelp('Une ligne par ligne d\'adresse (nom, rue, CP + ville, pays).');
        yield TextField::new('presidentName', 'Nom du président');

        yield Field::new('signatureUpload', 'Signature manuscrite (image)')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'required' => false,
                'mapped' => false,
                'attr' => ['accept' => 'image/png,image/jpeg,image/webp'],
            ])
            ->onlyOnForms()
            ->setHelp('PNG/JPG avec fond transparent de préférence. Max 2 Mo. Remplace la précédente.');

        yield TextField::new('signatureFilename', 'Signature actuelle')
            ->hideOnForm();

        yield TextareaField::new('legalFooter', 'Mentions légales (bas de facture)')
            ->setRequired(false)
            ->setNumOfRows(3)
            ->setHelp('Ex : SIRET, agrément Jeunesse et Sports, adresse du siège pour correspondance…');
    }

    public function index(AdminContext $context)
    {
        $settings = $this->repo->findCurrent();
        if ($settings === null) {
            $settings = new InvoiceSettings();
            $settings->setClubAddress('À compléter');
            $settings->setPresidentName('À compléter');
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

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->handleSignatureUpload($entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->handleSignatureUpload($entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    private function handleSignatureUpload(mixed $entity): void
    {
        if (!$entity instanceof InvoiceSettings) {
            return;
        }
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }
        $formData = $request->files->all('InvoiceSettings');
        $file = $formData['signatureUpload'] ?? null;
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return;
        }
        if ($file->getSize() > 2_000_000) {
            $this->addFlash('error', 'Signature trop lourde (max 2 Mo).');
            return;
        }
        if (!is_dir($this->signatureDir) && !@mkdir($this->signatureDir, 0775, true) && !is_dir($this->signatureDir)) {
            $this->addFlash('error', 'Impossible de créer le dossier de destination.');
            return;
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $name = 'signature-'.bin2hex(random_bytes(6)).'.'.$ext;
        try {
            $file->move($this->signatureDir, $name);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Échec upload : '.$e->getMessage());
            return;
        }
        // Supprime l'ancienne
        $previous = $entity->getSignatureFilename();
        if ($previous !== null) {
            @unlink($this->signatureDir.\DIRECTORY_SEPARATOR.$previous);
        }
        $entity->setSignatureFilename($name);
    }
}
