<?php

namespace App\Controller\Api\Public;

use App\Controller\Api\AbstractApiController;
use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\Order\OrderPaymentOrchestrator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/orders/{id}/payment-status', name: 'api_orders_payment_status_')]
class PaymentStatusController extends AbstractApiController
{
    // Limite de sécurité — empêche un polling infini côté backend
    // 40 tentatives x 3 secondes = 2 minutes, cohérent avec le compte à rebours du tunnel
    private const MAX_POLL_ATTEMPTS = 40;

    public function __construct(
        private readonly OrderPaymentOrchestrator $orchestrator,
        private readonly OrderRepository          $orderRepository,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── GET /api/orders/{id}/payment-status ───────────────────────────────
    /**
     * Endpoint de polling — appelé en boucle par React toutes les 3-5 secondes
     * pendant le compte à rebours du tunnel de commande (max 2 minutes).
     *
     * React arrête d'appeler dès que "isFinal": true dans la réponse.
     *
     * Réponse pendant le traitement :
     * {
     *   "success": true,
     *   "data": {
     *     "orderStatus": "pending_payment",
     *     "paymentStatus": "submitted",
     *     "isFinal": false,
     *     "statusInfo": {
     *       "title": "Paiement soumis",
     *       "message": "La demande a été envoyée à votre opérateur mobile.",
     *       "color": "info",
     *       "instruction": "Vérifiez votre téléphone pour confirmer."
     *     }
     *   }
     * }
     *
     * Réponse finale (succès) :
     * {
     *   "success": true,
     *   "data": {
     *     "orderStatus": "confirmed",
     *     "paymentStatus": "confirmed",
     *     "isFinal": true,
     *     "statusInfo": { "title": "Paiement réussi", "color": "success", ... }
     *   }
     * }
     *
     * Réponse finale (échec) :
     * {
     *   "success": true,
     *   "data": {
     *     "orderStatus": "payment_failed",
     *     "paymentStatus": "failed",
     *     "isFinal": true,
     *     "statusInfo": { "title": "Paiement échoué", "color": "error", ... },
     *     "failureMessage": "Solde insuffisant sur votre compte mobile."
     *   }
     * }
     */
    #[Route('', name: 'check', methods: ['GET'])]
    public function check(int $id): JsonResponse
    {
        $order = $this->orderRepository->findOneWithDetails($id);
        if (!$order) return $this->notFound('Commande introuvable.');

        // Commande en cash — pas de polling nécessaire, statut déjà définitif
        if ($order->isCashOnDelivery()) {
            return $this->success([
                'orderStatus'   => $order->getStatus(),
                'paymentStatus' => 'cash',
                'isFinal'       => true,
                'statusInfo'    => [
                    'title' => 'Paiement à la livraison',
                    'color' => 'info',
                ],
            ]);
        }

        // Sécurité — limite le nombre de vérifications pour une même commande
        if ($order->getPaymentPollAttempts() >= self::MAX_POLL_ATTEMPTS) {
            return $this->success([
                'orderStatus'   => $order->getStatus(),
                'paymentStatus' => 'timeout',
                'isFinal'       => true,
                'statusInfo'    => [
                    'title'       => 'Délai dépassé',
                    'message'     => 'La vérification automatique a expiré.',
                    'color'       => 'error',
                    'instruction' => 'Contactez le support si le montant a été débité.',
                ],
            ]);
        }

        try {
            $result = $this->orchestrator->pollPaymentStatus($order);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $response = [
            'orderStatus'   => $result['order']->getStatus(),
            'paymentStatus' => $result['payment']->getStatus(),
            'isFinal'       => $result['isFinal'],
            'statusInfo'    => $result['statusInfo'],
        ];

        if ($result['payment']->isFailed()) {
            $response['failureMessage'] = $result['payment']->getFailureMessage();
        }

        if ($result['payment']->isConfirmed()) {
            $response['orderNumber'] = $result['order']->getOrderNumber();
        }

        return $this->success($response);
    }
}