<?php

namespace App\Controller\Api\Admin;

use App\Repository\PageVisitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminStatsController — Donald Gros E-commerce
 *
 * Endpoints back-office pour consulter les statistiques de visites du site.
 * Sécurisé par ROLE_ADMIN (JWT admin requis).
 *
 * Routes :
 *   GET /api/admin/stats/overview       → KPIs résumé (total, aujourd'hui, semaine, mois)
 *   GET /api/admin/stats/visits-per-day → visites par jour (query param: days=30)
 *   GET /api/admin/stats/top-pages      → pages les plus visitées (query param: limit=10)
 *   GET /api/admin/stats/referrers      → sources de trafic (query param: limit=10)
 *   GET /api/admin/stats/peak-hours     → heures de pointe (toutes les heures 0–23)
 */
#[Route('/api/admin/stats', name: 'api_admin_stats_')]
#[IsGranted('ROLE_ADMIN')]
class AdminStatsController extends AbstractController
{
    public function __construct(
        private readonly PageVisitRepository $pageVisitRepository,
    ) {}

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function ok(mixed $data, string $message = 'OK'): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], Response::HTTP_OK);
    }

    // ── Endpoints ────────────────────────────────────────────────────────────

    /**
     * GET /api/admin/stats/overview
     *
     * KPIs résumé du dashboard :
     * - totalVisits         → toutes les visites depuis le début
     * - uniqueVisitors      → IPs distinctes depuis le début
     * - visitsToday         → visites aujourd'hui
     * - visitsThisWeek      → visites cette semaine (lundi → aujourd'hui)
     * - visitsThisMonth     → visites ce mois-ci
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "totalVisits": 1250,
     *     "uniqueVisitors": 834,
     *     "visitsToday": 42,
     *     "visitsThisWeek": 287,
     *     "visitsThisMonth": 891
     *   }
     * }
     */
    #[Route('/overview', name: 'overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return $this->ok([
            'totalVisits'     => $this->pageVisitRepository->countTotal(),
            'uniqueVisitors'  => $this->pageVisitRepository->countUniqueVisitors(),
            'visitsToday'     => $this->pageVisitRepository->countToday(),
            'visitsThisWeek'  => $this->pageVisitRepository->countThisWeek(),
            'visitsThisMonth' => $this->pageVisitRepository->countThisMonth(),
        ]);
    }

    /**
     * GET /api/admin/stats/visits-per-day?days=30
     *
     * Visites par jour sur les N derniers jours.
     * Utile pour le graphique courbe du dashboard.
     *
     * Query params :
     *   days (int, défaut 30, max 365)
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "days": 30,
     *     "chart": [
     *       { "day": "2026-09-01", "visits": 25 },
     *       { "day": "2026-09-02", "visits": 38 },
     *       ...
     *     ]
     *   }
     * }
     */
    #[Route('/visits-per-day', name: 'visits_per_day', methods: ['GET'])]
    public function visitsPerDay(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->query->get('days', 30)));

        return $this->ok([
            'days'  => $days,
            'chart' => $this->pageVisitRepository->getVisitsPerDay($days),
        ]);
    }

    /**
     * GET /api/admin/stats/top-pages?limit=10
     *
     * Pages les plus visitées du site.
     * Utile pour le graphique donut ou tableau du dashboard.
     *
     * Query params :
     *   limit (int, défaut 10, max 50)
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "topPages": [
     *       { "page": "/", "visits": 520 },
     *       { "page": "/products", "visits": 310 },
     *       ...
     *     ]
     *   }
     * }
     */
    #[Route('/top-pages', name: 'top_pages', methods: ['GET'])]
    public function topPages(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));

        return $this->ok([
            'topPages' => $this->pageVisitRepository->getTopPages($limit),
        ]);
    }

    /**
     * GET /api/admin/stats/referrers?limit=10
     *
     * Sources de trafic (referrers) les plus fréquentes.
     * Utile pour le tableau "D'où viennent vos visiteurs".
     *
     * Query params :
     *   limit (int, défaut 10, max 50)
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "referrers": [
     *       { "source": "Accès direct", "visits": 400 },
     *       { "source": "https://facebook.com", "visits": 230 },
     *       ...
     *     ]
     *   }
     * }
     */
    #[Route('/referrers', name: 'referrers', methods: ['GET'])]
    public function referrers(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));

        return $this->ok([
            'referrers' => $this->pageVisitRepository->getTopReferrers($limit),
        ]);
    }

    /**
     * GET /api/admin/stats/peak-hours
     *
     * Nombre de visites par heure de la journée (0 à 23).
     * Utile pour le graphique barres "Heures de pointe".
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "peakHours": [
     *       { "hour": 0,  "visits": 5 },
     *       { "hour": 1,  "visits": 2 },
     *       ...
     *       { "hour": 18, "visits": 95 },
     *       { "hour": 19, "visits": 87 },
     *       ...
     *       { "hour": 23, "visits": 12 }
     *     ]
     *   }
     * }
     */
    #[Route('/peak-hours', name: 'peak_hours', methods: ['GET'])]
    public function peakHours(): JsonResponse
    {
        return $this->ok([
            'peakHours' => $this->pageVisitRepository->getVisitsPerHour(),
        ]);
    }
}
