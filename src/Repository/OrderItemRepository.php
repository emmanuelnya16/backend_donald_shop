<?php

namespace App\Repository;

use App\Entity\OrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }

    public function findByOrder(int $orderId): array
    {
        return $this->createQueryBuilder('oi')
            ->where('oi.order = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Produits les plus vendus — utilisé pour le dashboard admin
     * et la section "Meilleures Ventes" en cas de recalcul.
     */
    public function findTopSellingVariants(int $limit = 10): array
    {
        return $this->createQueryBuilder('oi')
            ->select('oi.productVariant, SUM(oi.quantity) as totalSold')
            ->join('oi.order', 'o')
            ->where('o.status IN (:validStatuses)')
            ->setParameter('validStatuses', ['confirmed', 'processing', 'shipped', 'delivered'])
            ->groupBy('oi.productVariant')
            ->orderBy('totalSold', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function save(OrderItem $item, bool $flush = false): void
    {
        $this->getEntityManager()->persist($item);
        if ($flush) $this->getEntityManager()->flush();
    }
}