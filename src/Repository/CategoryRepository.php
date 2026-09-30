<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    // Toutes les catégories racines (sans parent) triées par position
    public function findRoots(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parent IS NULL')
            ->andWhere('c.isActive = true')
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Toutes les catégories racines avec leurs enfants en une seule requête
    public function findRootsWithChildren(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'ch')
            ->addSelect('ch')
            ->where('c.parent IS NULL')
            ->andWhere('c.isActive = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('ch.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Enfants directs d'une catégorie
    public function findChildren(int $parentId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parent = :parentId')
            ->andWhere('c.isActive = true')
            ->setParameter('parentId', $parentId)
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.slug = :slug')
            ->setParameter('slug', $slug);

        if ($excludeId) {
            $qb->andWhere('c.id != :id')
               ->setParameter('id', $excludeId);
        }

        return (int) $qb->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    // Toutes les catégories pour le back-office (actives et inactives)
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.parent', 'p')
            ->addSelect('p')
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Category $category, bool $flush = false): void
    {
        $this->getEntityManager()->persist($category);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function remove(Category $category, bool $flush = false): void
    {
        $this->getEntityManager()->remove($category);
        if ($flush) $this->getEntityManager()->flush();
    }


    /**
 * Compte les produits d'une catégorie
 * EN INCLUANT les produits de ses sous-catégories.
 */
public function countProductsRecursive(int $categoryId): int
{
    // Compte les produits directement dans cette catégorie
    // + les produits dans toutes ses sous-catégories
    $result = $this->getEntityManager()
        ->createQuery(
            'SELECT COUNT(p.id)
             FROM App\Entity\Product p
             JOIN p.category c
             WHERE (
                 c.id = :categoryId
                 OR c.parent = :categoryId
             )
             AND p.status = :status'
        )
        ->setParameter('categoryId', $categoryId)
        ->setParameter('status', 'active')
        ->getSingleScalarResult();

    return (int) $result;
}
}