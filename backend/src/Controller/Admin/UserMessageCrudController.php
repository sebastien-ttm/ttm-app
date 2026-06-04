<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserMessage;
use App\Message\NotifyUserMessageReplyMessage;
use App\Repository\UserMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * CRUD admin pour les messages entrants depuis l'app mobile.
 *
 * Visibilité :
 *  - Admin : voit tous les messages.
 *  - Entraîneur : voit uniquement ceux dont il est le destinataire.
 *  - Les éditeurs n'ont pas l'entrée de menu (setPermission au menu Dashboard).
 *
 * La réponse est limitée à UNE fois : une fois `reply` posée et horodatée,
 * le champ devient invisible en édition et toute tentative de modification
 * est bloquée dans updateEntity.
 */
class UserMessageCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserMessageRepository $messages,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return UserMessage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Message')
            ->setEntityLabelInPlural('Messages reçus')
            ->setEntityPermission('ROLE_ENTRAINEUR')
            ->setDefaultSort(['sentAt' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['subject', 'body', 'sender.nom', 'sender.prenom', 'sender.email']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // L'utilisateur mobile crée les messages via l'API : pas de bouton « Ajouter »
        // ni de suppression depuis le backend (on conserve l'historique).
        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    /**
     * Scope la requête index selon le viewer : un entraîneur ne voit que
     * ses propres messages, l'admin voit tout.
     */
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        /** @var User $viewer */
        $viewer = $this->getUser();
        $qb = $this->messages->createScopedQueryBuilder($viewer, 'entity');

        // Réapplique la recherche EasyAdmin par-dessus
        if ($searchDto->getQuery() !== null && $searchDto->getQuery() !== '') {
            $term = '%'.$searchDto->getQuery().'%';
            $qb->andWhere('entity.subject LIKE :q OR entity.body LIKE :q OR sender.nom LIKE :q OR sender.prenom LIKE :q OR sender.email LIKE :q')
                ->setParameter('q', $term);
        }

        // Tri demandé (par défaut sentAt DESC, mais EasyAdmin peut surcharger)
        foreach ($searchDto->getSort() as $field => $direction) {
            $qb->addOrderBy('entity.'.$field, $direction);
        }

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        $context = $this->getContext();
        $isReplied = false;
        if ($context !== null) {
            $entity = $context->getEntity()->getInstance();
            if ($entity instanceof UserMessage) {
                $isReplied = $entity->hasReply();
            }
        }

        yield DateTimeField::new('sentAt', 'Reçu le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnForm();

        yield AssociationField::new('sender', 'Expéditeur')
            ->setCrudController(UserCrudController::class)
            ->hideOnForm();

        yield TextField::new('recipientLabel', 'Destinataire')
            ->hideOnForm()
            ->setHelp('« Le club » = destiné à tous les admins. Sinon nom de l\'entraîneur ciblé.');

        yield TextField::new('subject', 'Objet')
            ->setRequired(false)
            ->hideOnForm();

        yield TextareaField::new('body', 'Message')
            ->setNumOfRows(6)
            ->hideOnForm();

        yield TextareaField::new('reply', $isReplied ? 'Votre réponse (envoyée)' : 'Répondre')
            ->setNumOfRows(6)
            ->setRequired(false)
            ->setFormTypeOptions(['attr' => $isReplied ? ['readonly' => true, 'disabled' => true] : []])
            ->setHelp($isReplied
                ? 'La réponse a déjà été envoyée et ne peut plus être modifiée.'
                : 'Saisissez votre réponse. Elle sera visible par l\'expéditeur dans l\'app mobile. Une seule réponse possible — vous ne pourrez plus la modifier ensuite.');

        yield DateTimeField::new('repliedAt', 'Répondu le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnForm()
            ->setRequired(false);

        yield AssociationField::new('repliedBy', 'Répondu par')
            ->hideOnForm()
            ->setRequired(false);

        yield DateTimeField::new('recipientsNotifiedAt', 'Email destinataires envoyé le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnForm()
            ->onlyOnDetail()
            ->setHelp('Horodatage de la dispatch async. Vide = pas encore traité par la queue Messenger.');

        yield DateTimeField::new('senderRepliedNotifiedAt', 'Email réponse envoyé le')
            ->setFormat('d MMM yyyy HH:mm')
            ->hideOnForm()
            ->onlyOnDetail();
    }

    /**
     * Bloque toute modification après que la réponse ait été posée, et
     * crédite l'auteur + l'horodatage à la première écriture.
     */
    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if (!$entityInstance instanceof UserMessage) {
            parent::updateEntity($em, $entityInstance);
            return;
        }

        // Vérifie en BDD si une réponse était déjà posée AVANT le submit
        // (l'instance a été hydratée depuis le form, donc reply peut avoir
        // été mutée). Pour la décision, on regarde repliedAt en base.
        $unitOfWork = $em->getUnitOfWork();
        $original = $unitOfWork->getOriginalEntityData($entityInstance);
        $wasAlreadyReplied = isset($original['repliedAt']) && $original['repliedAt'] !== null;

        if ($wasAlreadyReplied) {
            // Annule toute mutation en mémoire (form bypass) et n'écrit rien.
            $em->refresh($entityInstance);
            $this->addFlash('warning', 'Cette réponse a déjà été envoyée et ne peut plus être modifiée.');
            return;
        }

        // Première réponse : si le texte est non vide, crédite l'auteur +
        // horodatage via setReplyOnce (refuse aussi les whitespace-only).
        $reply = $entityInstance->getReply();
        $replyAccepted = false;
        if (is_string($reply) && trim($reply) !== '') {
            /** @var User $author */
            $author = $this->getUser();
            $replyAccepted = $entityInstance->setReplyOnce($reply, $author);
        }

        parent::updateEntity($em, $entityInstance);

        // Notification email à l'expéditeur (idempotence via timestamp côté handler).
        if ($replyAccepted && $entityInstance->getId() !== null) {
            $this->bus->dispatch(new NotifyUserMessageReplyMessage($entityInstance->getId()));
        }
    }
}
