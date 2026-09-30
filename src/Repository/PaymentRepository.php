<?php

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * Retrouve un paiement par son depositId PawaPay.
     * Utilisé pendant le polling : GET /api/orders/{id}/payment-status
     */
    public function findByDepositId(string $depositId): ?Payment
    {
        return $this->findOneBy(['depositId' => $depositId]);
    }

    /**
     * Le dernier paiement (le plus récent) associé à une commande.
     * Une commande peut avoir plusieurs tentatives de paiement
     * (ex: 1er essai MTN échoué, 2e essai réussi).
     */
    public function findLatestForOrder(int $orderId): ?Payment
    {
        return $this->createQueryBuilder('p')
            ->where('p.order = :orderId')
            ->setParameter('orderId', $orderId)
            ->orderBy('p.initiatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Tous les paiements encore en attente de confirmation.
     * Utile pour une tâche cron qui vérifierait en masse les paiements
     * bloqués depuis trop longtemps.
     */
    public function findPendingOlderThan(int $minutesThreshold): array
    {
        $threshold = new \DateTimeImmutable("-{$minutesThreshold} minutes");

        return $this->createQueryBuilder('p')
            ->where('p.status IN (:statuses)')
            ->andWhere('p.initiatedAt < :threshold')
            ->setParameter('statuses', ['initiated', 'submitted', 'processing'])
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    public function save(Payment $payment, bool $flush = false): void
    {
        $this->getEntityManager()->persist($payment);
        if ($flush) $this->getEntityManager()->flush();
    }
}