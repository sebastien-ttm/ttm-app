<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserMessage;
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
     * Messages envoyés par un utilisateur (ses propres conversations).
     *
     * @return list<UserMessage>
     */
    public function findSentBy(User $sender): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.recipient', 'r')->addSelect('r')
            ->leftJoin('m.repliedBy', 'rep')->addSelect('rep')
            ->where('m.sender = :u')
            ->setParameter('u', $sender)
            ->orderBy('m.sentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Query builder pour le CRUD admin, scopé selon le rôle du viewer :
     *  - admin : voit TOUS les messages
     *  - entraineur : ne voit QUE ceux où il est destinataire (recipient=self).
     *    Les messages « au club » (recipient=null) ne lui sont jamais visibles.
     *  - autres (éditeur) : aucune visibilité (le CRUD a quand même
     *    setEntityPermission ROLE_ENTRAINEUR ailleurs pour bloquer en amont)
     */
    public function createScopedQueryBuilder(User $viewer, string $alias = 'm'): QueryBuilder
    {
        $qb = $this->createQueryBuilder($alias)
            ->leftJoin($alias.'.sender', 'sender')->addSelect('sender')
            ->leftJoin($alias.'.recipient', 'recipient')->addSelect('recipient');

        if ($viewer->isAdmin()) {
            return $qb;
        }
        // ROLE_ENTRAINEUR (et plus haut) sans être admin : on filtre
        $qb->andWhere($alias.'.recipient = :viewer')
            ->setParameter('viewer', $viewer);
        return $qb;
    }
}
