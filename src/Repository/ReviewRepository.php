<?php

namespace App\Repository;

use App\Entity\Review;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * Avis publiés d'un produit — affiché sur la fiche produit publique.
     */
    public function findPublishedByProduct(int $productId, int $limit = 10, int $offset = 0): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')
            ->addSelect('u')
            ->where('r.product = :productId')
            ->andWhere('r.status = :status')
            ->setParameter('productId', $productId)
            ->setParameter('status', Review::STATUS_PUBLISHED)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les avis publiés d'un produit — pour la pagination.
     */
    public function countPublishedByProduct(int $productId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.product = :productId')
            ->andWhere('r.status = :status')
            ->setParameter('productId', $productId)
            ->setParameter('status', Review::STATUS_PUBLISHED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Calcule la note moyenne et le nombre d'avis publiés d'un produit.
     * Utilisé pour mettre à jour Product.averageRating et Product.reviewCount.
     */
    public function computeStats(int $productId): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) as avgRating, COUNT(r.id) as total')
            ->where('r.product = :productId')
            ->andWhere('r.status = :status')
            ->setParameter('productId', $productId)
            ->setParameter('status', Review::STATUS_PUBLISHED)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'averageRating' => $result['avgRating'] ? round((float) $result['avgRating'], 1) : null,
            'reviewCount'   => (int) $result['total'],
        ];
    }

    /**
     * Distribution des notes pour l'affichage des barres de progression.
     * Ex : { 5: 18, 4: 6, 3: 2, 2: 1, 1: 0 }
     */
    public function getRatingDistribution(int $productId): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.rating, COUNT(r.id) as cnt')
            ->where('r.product = :productId')
            ->andWhere('r.status = :status')
            ->setParameter('productId', $productId)
            ->setParameter('status', Review::STATUS_PUBLISHED)
            ->groupBy('r.rating')
            ->getQuery()
            ->getResult();

        // Initialise toutes les notes à 0
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($rows as $row) {
            $distribution[(int) $row['rating']] = (int) $row['cnt'];
        }

        return $distribution;
    }

    /**
     * Vérifie si un client a déjà laissé un avis sur un produit.
     * Un client ne peut laisser qu'un seul avis par produit.
     */
    public function hasUserReviewedProduct(int $userId, int $productId): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.user = :userId')
            ->andWhere('r.product = :productId')
            ->setParameter('userId', $userId)
            ->setParameter('productId', $productId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Vérifie si un client a bien acheté un produit — prérequis pour laisser un avis.
     * On vérifie dans les OrderItems des commandes confirmées/livrées.
     */
    public function hasUserPurchasedProduct(int $userId, int $productId): bool
    {
        $result = $this->getEntityManager()
            ->createQuery(
                'SELECT COUNT(oi.id)
                 FROM App\Entity\OrderItem oi
                 JOIN oi.order o
                 JOIN oi.productVariant v
                 JOIN v.product p
                 WHERE o.user = :userId
                 AND p.id = :productId
                 AND o.status IN (:statuses)'
            )
            ->setParameter('userId', $userId)
            ->setParameter('productId', $productId)
            ->setParameter('statuses', ['confirmed', 'processing', 'shipped', 'delivered'])
            ->getSingleScalarResult();

        return (int) $result > 0;
    }

    /**
     * File de modération pour l'admin.
     * Filtres : status (pending/published/rejected), productId
     */
    public function findForAdmin(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')
            ->leftJoin('r.product', 'p')
            ->addSelect('u', 'p')
            ->orderBy('r.createdAt', 'DESC');

        if (!empty($filters['status'])) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['productId'])) {
            $qb->andWhere('r.product = :productId')
               ->setParameter('productId', $filters['productId']);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Nombre d'avis en attente de modération — pour le badge dans la sidebar admin.
     */
    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :status')
            ->setParameter('status', Review::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(Review $review, bool $flush = false): void
    {
        $this->getEntityManager()->persist($review);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function remove(Review $review, bool $flush = false): void
    {
        $this->getEntityManager()->remove($review);
        if ($flush) $this->getEntityManager()->flush();
    }
}