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
     * Messages envoyés par un utilisateur. Par défaut on masque les
     * archivés — passer $includeArchived=true pour la vue « archivés ».
     *
     * @return list<UserMessage>
     */
    public function findSentBy(User $sender, bool $includeArchived = false): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.recipient', 'r')->addSelect('r')
            ->leftJoin('m.repliedBy', 'rep')->addSelect('rep')
            ->where('m.sender = :u')
            ->setParameter('u', $sender)
            ->orderBy('m.sentAt', 'DESC');

        if (!$includeArchived) {
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
     * Query builder pour le CRUD admin, scopé selon le rôle du viewer :
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
