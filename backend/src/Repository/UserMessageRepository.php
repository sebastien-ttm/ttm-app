<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMessage;
use App\Enum\MessageScope;
use App\Enum\Profile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMessage>
 */
class UserMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMessage::class);
    }

    /**
     * Liste les entraîneurs actifs sélectionnables comme destinataires depuis
     * l'app mobile. Profil 'entraineur' uniquement (pas 'encadrant') —
     * cohérent avec la spec « au club ou à un entraîneur ».
     *
     * @return list<User>
     */
    public function findSelectableTrainers(): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.isActive = true')
            ->andWhere('JSON_CONTAINS(u.profiles, :tag) = 1')
            ->setParameter('tag', json_encode(Profile::Entraineur->value))
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Messages envoyés par un utilisateur.
     *
     *  - $archivedOnly = false (défaut) → messages courants (senderArchivedAt IS NULL)
     *  - $archivedOnly = true           → messages archivés uniquement (IS NOT NULL)
     *
     * NB : les deux vues sont MUTUELLEMENT EXCLUSIVES. L'ancienne
     * signature `$includeArchived` renvoyait tout dans le cas true,
     * ce qui faisait apparaître les messages courants dans l'onglet
     * « Archivés » (et bloquait le rafraîchissement post-désarchivage).
     *
     * @return list<UserMessage>
     */
    public function findSentBy(User $sender, bool $archivedOnly = false): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.recipient', 'r')->addSelect('r')
            ->leftJoin('m.repliedBy', 'rep')->addSelect('rep')
            ->where('m.sender = :u')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('u', $sender)
            ->orderBy('m.sentAt', 'DESC');

        if ($archivedOnly) {
            $qb->andWhere('m.senderArchivedAt IS NOT NULL');
        } else {
            $qb->andWhere('m.senderArchivedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Messages où $viewer est DESTINATAIRE (boîte de réception mobile).
     *
     * Règles de visibilité :
     *  - scope=trainer      : uniquement si recipient = $viewer
     *  - scope=all_trainers : uniquement si $viewer a le profil Entraineur
     *  - scope=club         : uniquement si $viewer est ROLE_ADMIN
     *
     * L'archivage individuel est joint via UserMessageRecipientState
     * (côté appelant) — cette méthode n'applique PAS le filtre archivé.
     *
     * @return list<UserMessage>
     */
    public function findInboxFor(User $viewer): array
    {
        $isAdmin = $viewer->isAdmin();
        $isTrainer = $viewer->isEntraineur();
        if (!$isAdmin && !$isTrainer) {
            return [];
        }

        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.sender', 's')->addSelect('s')
            ->leftJoin('m.recipient', 'r')->addSelect('r')
            ->leftJoin('m.repliedBy', 'rep')->addSelect('rep')
            ->andWhere('m.deletedAt IS NULL')
            ->orderBy('m.sentAt', 'DESC');

        $orExpr = $qb->expr()->orX();

        if ($isAdmin) {
            $orExpr->add('m.scope = :sClub');
            $qb->setParameter('sClub', MessageScope::Club);
        }
        if ($isTrainer) {
            $orExpr->add('m.scope = :sAll');
            $qb->setParameter('sAll', MessageScope::AllTrainers);
            $orExpr->add('(m.scope = :sTrainer AND m.recipient = :viewer)');
            $qb->setParameter('sTrainer', MessageScope::Trainer);
            $qb->setParameter('viewer', $viewer);
        } else {
            // Admin non-entraîneur : peut aussi être destinataire nommé si
            // quelqu'un lui écrit directement (rare mais légal — un admin
            // qui coache également figurera avec les entraîneurs).
            $orExpr->add('(m.scope = :sTrainer AND m.recipient = :viewer)');
            $qb->setParameter('sTrainer', MessageScope::Trainer);
            $qb->setParameter('viewer', $viewer);
        }

        $qb->andWhere($orExpr);
        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les messages « unread » du point de vue du viewer :
     *  - inbox   : messages qu'il voit dans sa boîte de réception
     *              (mêmes filtres de scope que findInboxFor), sans
     *              réponse ET non archivés dans son propre état
     *              (UserMessageRecipientState.archivedAt IS NULL) et
     *              jamais ouverts par lui (readAt IS NULL). Ouvrir le
     *              détail ou archiver suffit à le faire disparaître du
     *              badge, même si aucune réponse formelle n'a été postée.
     *  - replies : messages qu'il a envoyés et qui ont reçu une
     *              réponse, sans être archivés côté expéditeur, tant
     *              qu'il n'a pas ouvert le message depuis cette réponse
     *              (senderReadAt absent ou antérieur à repliedAt).
     *
     * @return array{inbox: int, replies: int}
     */
    public function countUnreadFor(User $viewer): array
    {
        // 1) Répliques reçues sur mes envois (sender = viewer, reply
        //    présent, senderArchivedAt null) pas encore ouvertes depuis.
        $replies = (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.sender = :viewer')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.reply IS NOT NULL')
            ->andWhere('m.senderArchivedAt IS NULL')
            ->andWhere('(m.senderReadAt IS NULL OR m.senderReadAt < m.repliedAt)')
            ->setParameter('viewer', $viewer)
            ->getQuery()
            ->getSingleScalarResult();

        // 2) Inbox à traiter — filtre scope identique à findInboxFor
        //    + exclusion des messages archivés par le viewer (état
        //    UserMessageRecipientState) + reply IS NULL.
        $isAdmin = $viewer->isAdmin();
        $isTrainer = $viewer->isEntraineur();
        $inbox = 0;
        if ($isAdmin || $isTrainer) {
            $qb = $this->createQueryBuilder('m')
                ->select('COUNT(m.id)')
                ->leftJoin(
                    \App\Entity\UserMessageRecipientState::class,
                    'st',
                    'WITH',
                    'st.message = m AND st.user = :viewer',
                )
                ->andWhere('m.deletedAt IS NULL')
                ->andWhere('m.reply IS NULL')
                ->andWhere('st.archivedAt IS NULL')
                ->andWhere('st.readAt IS NULL')
                ->setParameter('viewer', $viewer);

            $orExpr = $qb->expr()->orX();
            if ($isAdmin) {
                $orExpr->add('m.scope = :sClub');
                $qb->setParameter('sClub', MessageScope::Club);
            }
            if ($isTrainer) {
                $orExpr->add('m.scope = :sAll');
                $qb->setParameter('sAll', MessageScope::AllTrainers);
                $orExpr->add('(m.scope = :sTrainer AND m.recipient = :viewer)');
                $qb->setParameter('sTrainer', MessageScope::Trainer);
            } else {
                $orExpr->add('(m.scope = :sTrainer AND m.recipient = :viewer)');
                $qb->setParameter('sTrainer', MessageScope::Trainer);
            }
            $qb->andWhere($orExpr);
            $inbox = (int) $qb->getQuery()->getSingleScalarResult();
        }

        return ['inbox' => $inbox, 'replies' => $replies];
    }

    /**
     * Query builder pour le CRUD admin, scopé selon le rôle du viewer (les messages supprimés par leur expéditeur y
     * restent visibles, avec leur date de suppression : le backend conserve l'historique) :
     *  - admin : voit TOUS les messages
     *  - entraineur : voit les scope=trainer où il est destinataire + tous
     *    les scope=all_trainers. Les scope=club (admins) restent invisibles.
     */
    public function createScopedQueryBuilder(User $viewer, string $alias = 'm'): QueryBuilder
    {
        $qb = $this->createQueryBuilder($alias)
            ->leftJoin($alias.'.sender', 'sender')->addSelect('sender')
            ->leftJoin($alias.'.recipient', 'recipient')->addSelect('recipient');

        if ($viewer->isAdmin()) {
            return $qb;
        }

        // ROLE_ENTRAINEUR : trainer nommé sur soi OU all_trainers
        $qb->andWhere($alias.'.scope = :sAll OR ('.$alias.'.scope = :sTrainer AND '.$alias.'.recipient = :viewer)')
            ->setParameter('sAll', MessageScope::AllTrainers)
            ->setParameter('sTrainer', MessageScope::Trainer)
            ->setParameter('viewer', $viewer);
        return $qb;
    }
}
