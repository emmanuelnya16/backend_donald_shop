<?php

namespace App\Repository;

use App\Entity\PageVisit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * PageVisitRepository — Donald Gros E-commerce
 *
 * Toutes les requêtes de lecture pour les stats de visites.
 *
 * @extends ServiceEntityRepository<PageVisit>
 */
class PageVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageVisit::class);
    }

    // ── Stats globales ────────────────────────────────────────────────────

    /**
     * Nombre total de visites (toutes pages confondues).
     */
    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de visiteurs uniques (IPs distinctes).
     */
    public function countUniqueVisitors(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.ip)')
            ->where('p.ip IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ── Visites par jour ──────────────────────────────────────────────────

    /**
     * Visites par jour sur les N derniers jours.
     * Retourne : [['day' => '2026-10-01', 'visits' => 42], ...]
     *
     * @return array<int, array{day: string, visits: int}>
     */
    public function getVisitsPerDay(int $days = 30): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $since = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');

        $sql = '
            SELECT DATE(visited_at) AS day, COUNT(id) AS visits
            FROM page_visits
            WHERE visited_at >= :since
            GROUP BY DATE(visited_at)
            ORDER BY day ASC
        ';

        $rows = $conn->fetchAllAssociative($sql, ['since' => $since]);

        return array_map(fn($r) => [
            'day'    => (string) $r['day'],
            'visits' => (int) $r['visits'],
        ], $rows);
    }

    // ── Top pages ─────────────────────────────────────────────────────────

    /**
     * Pages les plus visitées.
     * Retourne : [['page' => '/products', 'visits' => 120], ...]
     *
     * @return array<int, array{page: string, visits: int}>
     */
    public function getTopPages(int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.page, COUNT(p.id) AS visits')
            ->groupBy('p.page')
            ->orderBy('visits', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn($r) => [
            'page'   => $r['page'],
            'visits' => (int) $r['visits'],
        ], $rows);
    }

    // ── Sources de trafic (referrers) ─────────────────────────────────────

    /**
     * Sources de trafic les plus fréquentes.
     * Retourne : [['source' => 'facebook.com', 'visits' => 80], ...]
     *
     * @return array<int, array{source: string, visits: int}>
     */
    public function getTopReferrers(int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.referrer AS source, COUNT(p.id) AS visits')
            ->groupBy('p.referrer')
            ->orderBy('visits', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(fn($r) => [
            'source' => $r['source'] ?: 'Accès direct',
            'visits' => (int) $r['visits'],
        ], $rows);
    }

    // ── Heures de pointe ──────────────────────────────────────────────────

    /**
     * Nombre de visites par heure de la journée (0–23).
     * Retourne : [['hour' => 18, 'visits' => 65], ...]
     *
     * @return array<int, array{hour: int, visits: int}>
     */
    public function getVisitsPerHour(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT HOUR(visited_at) AS hour, COUNT(id) AS visits
            FROM page_visits
            GROUP BY HOUR(visited_at)
            ORDER BY hour ASC
        ';

        $rows = $conn->fetchAllAssociative($sql);

        // Complète les heures manquantes avec 0 visites
        $byHour = [];
        foreach ($rows as $r) {
            $byHour[(int) $r['hour']] = (int) $r['visits'];
        }

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'visits' => $byHour[$h] ?? 0];
        }

        return $result;
    }

    // ── KPIs résumé ───────────────────────────────────────────────────────

    /**
     * Visites aujourd'hui.
     */
    public function countToday(): int
    {
        $today = new \DateTimeImmutable('today');

        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.visitedAt >= :today')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Visites cette semaine.
     */
    public function countThisWeek(): int
    {
        $week = new \DateTimeImmutable('monday this week');

        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.visitedAt >= :week')
            ->setParameter('week', $week)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Visites ce mois-ci.
     */
    public function countThisMonth(): int
    {
        $month = new \DateTimeImmutable('first day of this month');

        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.visitedAt >= :month')
            ->setParameter('month', $month)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
