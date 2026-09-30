<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findByOrderNumber(string $orderNumber): ?Order
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')
            ->leftJoin('o.deliveryAddress', 'da')
            ->leftJoin('o.payments', 'p')
            ->addSelect('i', 'da', 'p')
            ->where('o.orderNumber = :num')
            ->setParameter('num', $orderNumber)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Charge une commande complète avec toutes ses relations.
     * Utilisé pour la page de détail commande et le polling de paiement.
     */
    public function findOneWithDetails(int $id): ?Order
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')
            ->leftJoin('o.deliveryAddress', 'da')
            ->leftJoin('o.payments', 'p')
            ->leftJoin('o.user', 'u')
            ->addSelect('i', 'da', 'p', 'u')
            ->where('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Historique des commandes d'un client connecté.
     * Triées de la plus récente à la plus ancienne.
     */
    public function findByUser(User $user, int $page = 1, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')
            ->leftJoin('o.payments', 'p')
            ->addSelect('i', 'p')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $total = (clone $qb)->select('COUNT(DISTINCT o.id)')->getQuery()->getSingleScalarResult();

        return [
            'orders' => $qb->getQuery()->getResult(),
            'total'  => (int) $total,
            'page'   => $page,
            'limit'  => $limit,
        ];
    }

    /**
     * Liste back-office avec filtres : statut, mode de paiement, dates, recherche.
     * Avec pagination pour éviter de charger toutes les commandes en mémoire.
     *
     * Retourne ['orders' => Order[], 'total' => int, 'page' => int, 'limit' => int]
     */
    public function findForAdmin(array $filters = [], int $page = 1, int $limit = 30): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.user', 'u')
            ->leftJoin('o.deliveryAddress', 'da')
            ->leftJoin('o.payments', 'p')   // ← FIX N+1 : charge les payments dès la première requête
            ->addSelect('u', 'da', 'p')
            ->orderBy('o.createdAt', 'DESC');

        if (!empty($filters['status'])) {
            $qb->andWhere('o.status = :status')->setParameter('status', $filters['status']);
        }

        if (!empty($filters['paymentMethod'])) {
            $qb->andWhere('o.paymentMethod = :pm')->setParameter('pm', $filters['paymentMethod']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'o.orderNumber LIKE :search',
                    'da.phone LIKE :search',
                    'da.firstName LIKE :search',
                    'da.lastName LIKE :search'
                )
            )->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('o.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTimeImmutable($filters['dateFrom']));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('o.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTimeImmutable($filters['dateTo'] . ' 23:59:59'));
        }

        // Compte total avant pagination (pour la pagination côté React)
        $countQb = (clone $qb)->select('COUNT(DISTINCT o.id)');
        $total   = (int) $countQb->getQuery()->getSingleScalarResult();

        $orders = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'orders' => $orders,
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
        ];
    }

    /**
     * Génère le prochain numéro de commande : DG-2025-0042
     */
    public function generateOrderNumber(): string
    {
        $year  = date('Y');
        $count = (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.orderNumber LIKE :prefix')
            ->setParameter('prefix', "DG-{$year}-%")
            ->getQuery()
            ->getSingleScalarResult();

        $next = $count + 1;
        return sprintf('DG-%s-%04d', $year, $next);
    }

    // Commandes en attente de paiement depuis trop longtemps (> X minutes)
    // Utile pour une tâche cron qui marque les paiements abandonnés comme expirés
    public function findStalePendingPayments(int $minutesThreshold = 15): array
    {
        $threshold = new \DateTimeImmutable("-{$minutesThreshold} minutes");

        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->andWhere('o.createdAt < :threshold')
            ->setParameter('status', Order::STATUS_PENDING_PAYMENT)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    public function save(Order $order, bool $flush = false): void
    {
        $this->getEntityManager()->persist($order);
        if ($flush) $this->getEntityManager()->flush();
    }
}