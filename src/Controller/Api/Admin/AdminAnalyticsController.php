<?php
// src/Controller/Api/Admin/AdminAnalyticsController.php
namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\Service\Analytics\AnalyticsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminAnalyticsController extends AbstractApiController
{
    #[Route('/analytics', name: 'admin_analytics', methods: ['GET'])]
    public function getStats(Request $request, AnalyticsService $analyticsService): JsonResponse
    {
        $startDate = $request->query->getString('startDate', '30daysAgo');
        $endDate   = $request->query->getString('endDate', 'today');

        // Valeurs autorisées uniquement (sécurité)
        $allowed = ['7daysAgo', '30daysAgo', '90daysAgo', 'today', 'yesterday'];
        if (!in_array($startDate, $allowed, true)) {
            $startDate = '30daysAgo';
        }

        try {
            $data = $analyticsService->getStats($startDate, $endDate);
            return $this->success($data);
        } catch (\Throwable $e) {
            return $this->error('Erreur Google Analytics : ' . $e->getMessage(), 500);
        }
    }
}
