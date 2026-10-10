<?php

namespace App\Controller\Admin;

use App\Entity\Mailing;
use App\Entity\User;
use App\Enum\Profile;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Mailings groupés : rédaction (sujet, contenu, audience). Une fois enregistré, le
 * mailing s'ouvre sur son écran de suivi (MailingAdminController) où l'on vérifie les
 * destinataires, envoie un test, lance l'envoi et suit son avancement.
 */
class MailingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Mailing::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Mailing')
            ->setEntityLabelInPlural('Mailings')
            ->setEntityPermission('ROLE_ADMIN')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp(
                Crud::PAGE_INDEX,
                'E-mails groupés aux adhérents. Rédigez le mailing, vérifiez les destinataires, envoyez-vous un test, puis lancez '
                .'l\'envoi : il part par petits lots espacés (quelques minutes pour des centaines d\'adhérents). '
                .'Chaque e-mail contient un lien de désinscription, respecté automatiquement.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        $open = Action::new('open', 'Ouvrir', 'fa fa-paper-plane')
            ->linkToRoute('admin_mailing_show', fn (Mailing $m) => ['id' => $m->getId()])
            ->displayIf(fn (Mailing $m) => $m->getId() !== null);

        return $actions
            ->disable(Action::DETAIL, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, $open)
            ->add(Crud::PAGE_EDIT, $open)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a->displayIf(fn (Mailing $m) => $m->isDraft()))
            ->reorder(Crud::PAGE_INDEX, ['open', Action::EDIT, Action::DELETE]);
    }

    public function createEntity(string $entityFqcn): Mailing
    {
        $mailing = new Mailing();
        $mailing->setBodyHtml('<p>Bonjour {{ prenom }},</p><p></p><p>Sportivement,<br>Le bureau du TTM</p>');
        $user = $this->getUser();
        if ($user instanceof User) {
            $mailing->setCreatedBy($user);
            // Les réponses arrivent chez l'auteur du mailing (modifiable).
            $mailing->setReplyTo($user->getEmail());
        }

        return $mailing;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('subject', 'Sujet')
            ->setHelp('Objet de l\'e-mail, tel que les adhérents le verront.');
        yield ChoiceField::new('status', 'État')
            ->setChoices(array_flip(Mailing::statusLabels()))
            ->renderAsBadges([
                Mailing::STATUS_DRAFT => 'secondary',
                Mailing::STATUS_SENDING => 'info',
                Mailing::STATUS_PAUSED => 'warning',
                Mailing::STATUS_DONE => 'success',
                Mailing::STATUS_CANCELLED => 'danger',
            ])
            ->hideOnForm();
        yield ChoiceField::new('audience', 'Destinataires (profils)')
            ->setChoices(Profile::choices())
            ->allowMultipleChoices()
            ->setRequired(false)
            ->renderAsBadges()
            ->setHelp('Si vide : tous les adhérents. Sinon, uniquement ceux qui ont au moins un des profils cochés.');
        yield BooleanField::new('includeExternal', 'Inclure les comptes externes')
            ->onlyOnForms()
            ->setHelp('Parents non licenciés et amis du club (comptes de type « externe »). Décoché : seuls les adhérents reçoivent le mailing.');
        yield EmailField::new('replyTo', 'Adresse de réponse')
            ->onlyOnForms()
            ->setRequired(false)
            ->setHelp('Les e-mails partent d\'une adresse « noreply » : indiquez ici l\'adresse où les adhérents peuvent répondre.');
        yield TextEditorField::new('bodyHtml', 'Contenu')
            ->setNumOfRows(25)
            ->onlyOnForms()
            ->setHelp(
                'Rédigez le message. <code>{{ prenom }}</code> et <code>{{ nom }}</code> sont remplacés par le prénom et le nom '
                .'de chaque adhérent. L\'en-tête du club et le lien de désinscription sont ajoutés automatiquement.'
            );
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
        yield DateTimeField::new('startedAt', 'Lancé le')->hideOnForm();
    }

    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        // Après création ou modification : sur l'écran de suivi du mailing.
        $mailing = $context->getEntity()->getInstance();
        if ($mailing instanceof Mailing && $mailing->getId() !== null && in_array($action, [Action::NEW, Action::EDIT], true)) {
            return $this->redirectToRoute('admin_mailing_show', ['id' => $mailing->getId()]);
        }

        return parent::getRedirectResponseAfterSave($context, $action);
    }
}
