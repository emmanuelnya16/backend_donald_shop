<?php

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    public function findByProduct(int $productId): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.product = :productId')
            ->andWhere('v.isActive = true')
            ->setParameter('productId', $productId)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Variantes en alerte de stock — pour le tableau de bord admin
    public function findLowStock(): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.product', 'p')
            ->addSelect('p')
            ->where('v.stock > 0')
            ->andWhere('v.stock <= v.alertThreshold')
            ->andWhere('v.isActive = true')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('v.stock', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Variantes en rupture de stock
    public function findOutOfStock(): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.product', 'p')
            ->addSelect('p')
            ->where('v.stock = 0')
            ->andWhere('v.isActive = true')
            ->getQuery()
            ->getResult();
    }

    public function skuExists(string $sku, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.sku = :sku')
            ->setParameter('sku', $sku);

        if ($excludeId) {
            $qb->andWhere('v.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(ProductVariant $variant, bool $flush = false): void
    {
        $this->getEntityManager()->persist($variant);
        if ($flush) $this->getEntityManager()->flush();
    }
}