<?php

namespace App\Repository;

use App\Entity\StaticPage;
use App\Entity\User;
use App\Service\Audience\AudienceFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaticPage>
 */
class StaticPageRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AudienceFilter $audienceFilter,
    ) {
        parent::__construct($registry, StaticPage::class);
    }

    public function findOneBySlugPublished(string $slug, ?User $viewer = null): ?StaticPage
    {
        $page = $this->findOneBy(['slug' => $slug, 'isPublished' => true]);
        if ($page === null) {
            return null;
        }
        // Vérification d'audience en mémoire (1 seul objet)
        if (!$this->audienceFilter->isVisible($page->getAudience(), $viewer)) {
            return null;
        }
        return $page;
    }

    /**
     * @return list<StaticPage>
     */
    public function findAllPublished(?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isPublished = true')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.title', 'ASC');
        $this->audienceFilter->apply($qb, $viewer, 'p');
        return $qb->getQuery()->getResult();
    }

    /**
     * Returns top-level published pages (no parent).
     *
     * @return list<StaticPage>
     */
    public function findRootsPublished(?User $viewer = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isPublished = true')
            ->andWhere('p.parent IS NULL')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.title', 'ASC');
        $this->audienceFilter->apply($qb, $viewer, 'p');
        return $qb->getQuery()->getResult();
    }
}
