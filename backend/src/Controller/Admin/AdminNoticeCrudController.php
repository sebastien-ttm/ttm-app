<?php

namespace App\Controller\Admin;

use App\Entity\AdminNotice;
use App\Entity\User;
use App\Enum\Profile;
use App\Repository\AdminNoticeRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD des messages ponctuels avec acquittement.
 *
 * publishedAt vide = brouillon (invisible). Une fois défini, la notice
 * est poussée à chaque user qui rouvre l'appli après > 10 min
 * d'inactivité, tant qu'il n'a pas cliqué « J'ai compris ».
 */
class AdminNoticeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminNoticeRepository $notices,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return AdminNotice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Message ponctuel')
            ->setEntityLabelInPlural('Messages ponctuels')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['publishedAt' => 'DESC', 'createdAt' => 'DESC'])
            ->setPaginatorPageSize(20)
            ->setSearchFields(['title', 'content']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title', 'Titre');

        yield TextEditorField::new('content', 'Contenu')
            ->setNumOfRows(12)
            ->setHelp('Contenu HTML riche. Affiché dans une modale plein écran à l\'ouverture de l\'appli, avec un bouton d\'acquittement.')
            ->onlyOnForms();

        yield TextField::new('acknowledgeLabel', 'Libellé du bouton')
            ->setHelp('Par défaut « J\'ai compris ». Personnalisable (« J\'accepte », « Ok », …).');

        yield DateTimeField::new('publishedAt', 'Publier à partir de')
            ->setRequired(false)
            ->setHelp('Vide = brouillon (invisible). Date passée = actif immédiatement. Date future = programmé.');

        yield DateTimeField::new('expiresAt', 'Expire le')
            ->setRequired(false)
            ->setHelp('Optionnel. Après cette date, la notice n\'est plus affichée. L\'historique reste consultable ici.');

        yield ChoiceField::new('audience', 'Audience cible')
            ->setChoices(Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Vide = tous les adhérents. Sinon, uniquement les profils sélectionnés.');

        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex();

        // Compteur d'acquittements en lecture seule (index + detail).
        yield IntegerField::new('acknowledgementCount', 'Acquittements')
            ->onlyOnIndex()
            ->formatValue(fn ($v, AdminNotice $n) => $this->notices->countAcknowledgementsFor($n));
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof AdminNotice) {
            /** @var User $author */
            $author = $this->getUser();
            $entityInstance->setCreatedBy($author);
        }
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if ($entityInstance instanceof AdminNotice) {
            $entityInstance->touchUpdatedAt();
        }
        parent::updateEntity($em, $entityInstance);
    }
}
