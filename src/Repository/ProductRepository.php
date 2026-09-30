<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Recherche paginée avec filtres — utilisée par la page catalogue publique.
     *
     * Filtres disponibles :
     *  - categoryId   : ID d'une catégorie (inclut les sous-catégories)
     *  - categorySlug : slug d'une catégorie
     *  - minPrice     : prix minimum en FCFA
     *  - maxPrice     : prix maximum en FCFA
     *  - sizes        : tableau de tailles ['S','M','L']
     *  - colors       : tableau de couleurs ['Bleu','Rouge']
     *  - inStockOnly  : bool — n'affiche que les produits en stock
     *  - minRating    : note minimale (1-5)
     *  - search       : recherche full-text sur nom et description
     *  - sortBy       : pertinence|price_asc|price_desc|newest|best_sellers|top_rated
     *  - page         : numéro de page (défaut 1)
     *  - limit        : produits par page (défaut 20)
     */
    public function findFiltered(array $filters = []): array
    {
        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(60, max(1, (int) ($filters['limit'] ?? 20)));

        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('p.images',   'i')
            ->leftJoin('p.category', 'c')
            ->addSelect('v', 'i', 'c')
            ->where('p.status = :status')
            ->setParameter('status', Product::STATUS_ACTIVE);

        // Filtre catégorie
        if (!empty($filters['categoryId'])) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'c.id = :catId',
                    'c.parent = :catId'  // inclut les sous-catégories
                )
            )->setParameter('catId', (int) $filters['categoryId']);
        }

        // Filtre slug catégorie
        if (!empty($filters['categorySlug'])) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'c.slug = :catSlug',
                    'IDENTITY(c.parent) IN (
                        SELECT pc.id FROM App\Entity\Category pc WHERE pc.slug = :catSlug
                    )'
                )
            )->setParameter('catSlug', $filters['categorySlug']);
        }

        // Filtre prix
        if (!empty($filters['minPrice'])) {
            $qb->andWhere('p.basePrice >= :minPrice')
               ->setParameter('minPrice', (int) $filters['minPrice']);
        }
        if (!empty($filters['maxPrice'])) {
            $qb->andWhere('p.basePrice <= :maxPrice')
               ->setParameter('maxPrice', (int) $filters['maxPrice']);
        }

        // Filtre tailles
        if (!empty($filters['sizes'])) {
            $qb->andWhere('v.size IN (:sizes)')
               ->setParameter('sizes', (array) $filters['sizes']);
        }

        // Filtre couleurs
        if (!empty($filters['colors'])) {
            $qb->andWhere('v.color IN (:colors)')
               ->setParameter('colors', (array) $filters['colors']);
        }

        // Filtre en stock uniquement
        if (!empty($filters['inStockOnly'])) {
            $qb->andWhere('v.stock > 0');
        }

        // Filtre note minimale
        if (!empty($filters['minRating'])) {
            $qb->andWhere('p.averageRating >= :minRating')
               ->setParameter('minRating', (float) $filters['minRating']);
        }

        // Recherche full-text
        if (!empty($filters['search'])) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'p.name LIKE :search',
                    'p.shortDescription LIKE :search'
                )
            )->setParameter('search', '%' . $filters['search'] . '%');
        }

        // Tri
        $this->applySorting($qb, $filters['sortBy'] ?? 'newest');

        // Total avant pagination
        $total = (clone $qb)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Pagination
        $products = $qb
            ->select('p', 'v', 'i', 'c')
            ->distinct()
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'products'    => $products,
            'total'       => (int) $total,
            'page'        => $page,
            'limit'       => $limit,
            'totalPages'  => (int) ceil($total / $limit),
        ];
    }

    private function applySorting(QueryBuilder $qb, string $sortBy): void
    {
        match ($sortBy) {
            'price_asc'    => $qb->orderBy('p.basePrice', 'ASC'),
            'price_desc'   => $qb->orderBy('p.basePrice', 'DESC'),
            'best_sellers' => $qb->orderBy('p.salesCount', 'DESC'),
            'top_rated'    => $qb->orderBy('p.averageRating', 'DESC'),
            'newest'       => $qb->orderBy('p.createdAt', 'DESC'),
            default        => $qb->orderBy('p.salesCount', 'DESC'), // pertinence
        };
    }

    public function findBySlug(string $slug): ?Product
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.variants', 'v')
            ->leftJoin('p.images',   'i')
            ->leftJoin('p.category', 'c')
            ->addSelect('v', 'i', 'c')
            ->where('p.slug = :slug')
            ->andWhere('p.status = :status')
            ->setParameter('slug', $slug)
            ->setParameter('status', Product::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findNewArrivals(int $limit = 8): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.images', 'i')
            ->addSelect('i')
            ->where('p.status = :status')
            ->setParameter('status', Product::STATUS_ACTIVE)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findBestSellers(int $limit = 8): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.images', 'i')
            ->addSelect('i')
            ->where('p.status = :status')
            ->setParameter('status', Product::STATUS_ACTIVE)
            ->orderBy('p.salesCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOnSale(int $limit = 8): array
    {
        $now = new \DateTimeImmutable();
        return $this->createQueryBuilder('p')
            ->leftJoin('p.images', 'i')
            ->addSelect('i')
            ->where('p.status = :status')
            ->andWhere('p.promoPrice IS NOT NULL')
            ->andWhere('(p.promoStartsAt IS NULL OR p.promoStartsAt <= :now)')
            ->andWhere('(p.promoEndsAt IS NULL OR p.promoEndsAt >= :now)')
            ->setParameter('status', Product::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    // Produits similaires — même catégorie, pas le même produit
    public function findSimilar(int $productId, int $categoryId, int $limit = 6): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.images', 'i')
            ->addSelect('i')
            ->where('p.status = :status')
            ->andWhere('p.category = :catId')
            ->andWhere('p.id != :productId')
            ->setParameter('status', Product::STATUS_ACTIVE)
            ->setParameter('catId', $categoryId)
            ->setParameter('productId', $productId)
            ->orderBy('p.salesCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.slug = :slug')
            ->setParameter('slug', $slug);

        if ($excludeId) {
            $qb->andWhere('p.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    // Recherche back-office avec tous les statuts
    public function findForAdmin(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->addSelect('c')
            ->orderBy('p.createdAt', 'DESC');

        if (!empty($filters['status'])) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['categoryId'])) {
            $qb->andWhere('p.category = :catId')
               ->setParameter('catId', $filters['categoryId']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('p.name LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return $qb->getQuery()->getResult();
    }

    public function save(Product $product, bool $flush = false): void
    {
        $this->getEntityManager()->persist($product);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function remove(Product $product, bool $flush = false): void
    {
        $this->getEntityManager()->remove($product);
        if ($flush) $this->getEntityManager()->flush();
    }
}