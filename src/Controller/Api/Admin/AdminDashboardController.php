<?php

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/dashboard', name: 'api_admin_dashboard_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminDashboardController extends AbstractApiController
{
    public function __construct(
        private readonly OrderRepository   $orderRepository,
        private readonly ProductRepository $productRepository,
        private readonly UserRepository    $userRepository,
        private readonly EntityManagerInterface $em,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    /**
     * GET /api/admin/dashboard
     *
     * Retourne toutes les métriques en un seul appel :
     * - KPIs du jour (CA, commandes, clients, ruptures)
     * - Répartition des commandes par statut
     * - Dernières commandes (5)
     * - Top 5 produits les plus vendus
     * - Chiffre d'affaires des 7 derniers jours (pour le graphique)
     */
    #[Route('', name: 'stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $today     = new \DateTimeImmutable('today');
        $yesterday = new \DateTimeImmutable('yesterday');
        $thisMonth = new \DateTimeImmutable('first day of this month');

        // ── KPI : CA du jour ─────────────────────────────────────────────────
        $revenueToday     = $this->getRevenueSince($today);
        $revenueYesterday = $this->getRevenueBetween($yesterday, $today);

        // ── KPI : Commandes du jour ──────────────────────────────────────────
        $ordersToday = $this->countOrdersSince($today);

        // ── KPI : Nouveaux clients ce mois ───────────────────────────────────
        $newClientsThisMonth = $this->em->createQuery(
            'SELECT COUNT(u) FROM App\Entity\User u WHERE u.createdAt >= :since'
        )->setParameter('since', $thisMonth)->getSingleScalarResult();

        // ── KPI : Ruptures de stock ──────────────────────────────────────────
        $outOfStock = $this->em->createQuery(
            'SELECT COUNT(v) FROM App\Entity\ProductVariant v WHERE v.stock = 0 AND v.isActive = true'
        )->getSingleScalarResult();

        $lowStock = $this->em->createQuery(
            'SELECT COUNT(v) FROM App\Entity\ProductVariant v WHERE v.stock > 0 AND v.stock <= 3 AND v.isActive = true'
        )->getSingleScalarResult();

        // ── Répartition des commandes par statut (tous les temps) ────────────
        $statusCounts = $this->getStatusCounts();

        // ── Panier moyen (commandes confirmées ce mois) ───────────────────────
        $avgOrderValue = $this->em->createQuery(
            'SELECT AVG(o.totalAmount) FROM App\Entity\Order o
             WHERE o.status IN (:statuses) AND o.createdAt >= :since'
        )
        ->setParameter('statuses', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])
        ->setParameter('since', $thisMonth)
        ->getSingleScalarResult();

        // ── Taux de paiement mobile ───────────────────────────────────────────
        $totalOrdersThisMonth  = $this->countOrdersSince($thisMonth);
        $mobileOrdersThisMonth = $this->em->createQuery(
            'SELECT COUNT(o) FROM App\Entity\Order o
             WHERE o.paymentMethod IN (:methods) AND o.createdAt >= :since'
        )
        ->setParameter('methods', [Order::PAYMENT_MTN, Order::PAYMENT_ORANGE])
        ->setParameter('since', $thisMonth)
        ->getSingleScalarResult();

        $mobileRate = $totalOrdersThisMonth > 0
            ? round(($mobileOrdersThisMonth / $totalOrdersThisMonth) * 100)
            : 0;

        // ── CA des 7 derniers jours (pour le graphique en courbe) ─────────────
        $revenueChart = $this->getRevenueLast7Days();

        // ── Dernières commandes ───────────────────────────────────────────────
        $latestOrders = $this->orderRepository->findForAdmin([], 1, 5)['orders'];

        // ── Top 5 produits les plus vendus ───────────────────────────────────
        $topProducts = $this->getTopProducts(5);

        return $this->success([
            'kpis' => [
                'revenueToday'       => (int) $revenueToday,
                'revenueYesterday'   => (int) $revenueYesterday,
                'ordersToday'        => (int) $ordersToday,
                'newClientsThisMonth'=> (int) $newClientsThisMonth,
                'outOfStock'         => (int) $outOfStock,
                'lowStock'           => (int) $lowStock,
                'avgOrderValue'      => (int) ($avgOrderValue ?? 0),
                'mobilePaymentRate'  => (int) $mobileRate,
            ],
            'ordersByStatus' => $statusCounts,
            'revenueChart'   => $revenueChart,
            'latestOrders'   => array_map(fn($o) => $this->formatOrderRow($o), $latestOrders),
            'topProducts'    => $topProducts,
        ]);
    }

    // ── Helpers privés ────────────────────────────────────────────────────────

    private function getRevenueSince(\DateTimeImmutable $since): int
    {
        return (int) $this->em->createQuery(
            'SELECT COALESCE(SUM(o.totalAmount), 0) FROM App\Entity\Order o
             WHERE o.status IN (:statuses) AND o.createdAt >= :since'
        )
        ->setParameter('statuses', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])
        ->setParameter('since', $since)
        ->getSingleScalarResult();
    }

    private function getRevenueBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->em->createQuery(
            'SELECT COALESCE(SUM(o.totalAmount), 0) FROM App\Entity\Order o
             WHERE o.status IN (:statuses) AND o.createdAt >= :from AND o.createdAt < :to'
        )
        ->setParameter('statuses', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])
        ->setParameter('from', $from)
        ->setParameter('to', $to)
        ->getSingleScalarResult();
    }

    private function countOrdersSince(\DateTimeImmutable $since): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(o) FROM App\Entity\Order o WHERE o.createdAt >= :since'
        )->setParameter('since', $since)->getSingleScalarResult();
    }

    /**
     * Nombre de commandes par statut (tous les temps).
     */
    private function getStatusCounts(): array
    {
        $rows = $this->em->createQuery(
            'SELECT o.status, COUNT(o) as cnt FROM App\Entity\Order o GROUP BY o.status'
        )->getResult();

        $map = [
            Order::STATUS_PENDING_PAYMENT => 0,
            Order::STATUS_CONFIRMED       => 0,
            Order::STATUS_PROCESSING      => 0,
            Order::STATUS_SHIPPED         => 0,
            Order::STATUS_DELIVERED       => 0,
            Order::STATUS_CANCELLED       => 0,
            Order::STATUS_PENDING_COD     => 0,
            Order::STATUS_PAYMENT_FAILED  => 0,
        ];

        foreach ($rows as $row) {
            if (isset($map[$row['status']])) {
                $map[$row['status']] = (int) $row['cnt'];
            }
        }

        return $map;
    }

    /**
     * CA par jour sur les 7 derniers jours — pour le graphique courbe.
     * Format : [{ day: 'Lun 01', amount: 145000 }, ...]
     */
    private function getRevenueLast7Days(): array
    {
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date     = new \DateTimeImmutable("-{$i} days");
            $nextDate = $date->modify('+1 day');

            $amount = (int) $this->em->createQuery(
                'SELECT COALESCE(SUM(o.totalAmount), 0) FROM App\Entity\Order o
                 WHERE o.status IN (:statuses)
                   AND o.createdAt >= :from AND o.createdAt < :to'
            )
            ->setParameter('statuses', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED])
            ->setParameter('from', $date->setTime(0, 0, 0))
            ->setParameter('to', $nextDate->setTime(0, 0, 0))
            ->getSingleScalarResult();

            $days[] = [
                'day'    => $date->format('D d'), // ex: Mon 01
                'label'  => $this->frDayLabel($date),
                'amount' => $amount,
            ];
        }

        return $days;
    }

    private function frDayLabel(\DateTimeImmutable $date): string
    {
        $days = ['Mon' => 'Lun', 'Tue' => 'Mar', 'Wed' => 'Mer', 'Thu' => 'Jeu', 'Fri' => 'Ven', 'Sat' => 'Sam', 'Sun' => 'Dim'];
        return ($days[$date->format('D')] ?? $date->format('D')) . ' ' . $date->format('d');
    }

    /**
     * Top N produits les plus vendus (basé sur salesCount du produit).
     */
    private function getTopProducts(int $limit): array
    {
        $products = $this->productRepository->createQueryBuilder('p')
            ->leftJoin('p.images', 'img')
            ->addSelect('img')
            ->where('p.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('p.salesCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn($p) => [
            'id'         => $p->getId(),
            'name'       => $p->getName(),
            'imageUrl'   => $p->getMainImage()?->getUrl(),
            'basePrice'  => $p->getBasePrice(),
            'salesCount' => $p->getSalesCount(),
            'category'   => $p->getCategory()?->getName(),
        ], $products);
    }

    /**
     * Format compact d'une commande pour le tableau "Dernières commandes".
     */
    private function formatOrderRow(Order $order): array
    {
        $address = $order->getDeliveryAddress();
        $payment = $order->getLatestPayment();

        return [
            'id'            => $order->getId(),
            'orderNumber'   => $order->getOrderNumber(),
            'status'        => $order->getStatus(),
            'statusLabel'   => $order->getStatusLabel(),
            'paymentMethod' => $order->getPaymentMethod(),
            'totalAmount'   => $order->getTotalAmount(),
            'createdAt'     => $order->getCreatedAt()->format('d/m/Y H:i'),
            'client'        => $address ? [
                'fullName' => $address->getFullName(),
                'phone'    => $address->getPhone(),
            ] : null,
            'paymentStatus' => $payment?->getStatus(),
        ];
    }
}
