<?php

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\Notification\SmsService;
use App\Service\Order\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/orders', name: 'api_admin_orders_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminOrderController extends AbstractApiController
{
    public function __construct(
        private readonly OrderRepository        $orderRepository,
        private readonly OrderService           $orderService,
        private readonly SmsService             $smsService,
        private readonly EntityManagerInterface $em,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── GET /api/admin/orders ─────────────────────────────────────────────
    /**
     * Liste toutes les commandes avec filtres.
     *
     * Query params optionnels :
     *   ?status=confirmed
     *   ?paymentMethod=mtn_momo
     *   ?search=DG-2025-0042   (ou numéro de téléphone, ou nom client)
     *   ?dateFrom=2025-01-01
     *   ?dateTo=2025-12-31
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "orders": [...],
     *     "total": 42,
     *     "stats": {
     *       "pending": 5,
     *       "confirmed": 12,
     *       "processing": 8,
     *       "shipped": 6,
     *       "delivered": 10,
     *       "cancelled": 1
     *     }
     *   }
     * }
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 30)));

        $result = $this->orderRepository->findForAdmin([
            'status'        => $request->query->get('status'),
            'paymentMethod' => $request->query->get('paymentMethod'),
            'search'        => $request->query->get('search'),
            'dateFrom'      => $request->query->get('dateFrom'),
            'dateTo'        => $request->query->get('dateTo'),
        ], $page, $limit);

        $orders = $result['orders'];

        // Statistiques rapides par statut — pour les badges dans l'UI admin
        $stats = $this->computeStats($orders);

        return $this->success([
            'orders' => array_map(
                fn($o) => $this->formatForList($o),
                $orders
            ),
            'total'       => $result['total'],
            'page'        => $result['page'],
            'limit'       => $result['limit'],
            'totalPages'  => (int) ceil($result['total'] / $result['limit']),
            'stats'       => $stats,
        ]);
    }

    // ── GET /api/admin/orders/{id} ────────────────────────────────────────
    /**
     * Détail complet d'une commande pour le back-office.
     * Inclut toutes les infos : articles, adresse, paiement, historique.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $order = $this->orderRepository->findOneWithDetails($id);
        if (!$order) return $this->notFound('Commande introuvable.');

        return $this->success($this->formatForAdmin($order));
    }

    // ── PATCH /api/admin/orders/{id}/status ───────────────────────────────
    /**
     * Met à jour le statut d'une commande.
     * C'est l'action principale de l'admin — elle gère toute la partie livraison.
     *
     * Body JSON :
     * {
     *   "status": "processing",    // nouveau statut
     *   "note": "Expédié via Yaounde Express, suivi: XYZ123"  // optionnel
     * }
     *
     * Transitions autorisées :
     *   confirmed   → processing  (préparation commencée)
     *   processing  → shipped     (remis au livreur)
     *   shipped     → delivered   (client a reçu)
     *   pending_cod → confirmed   (commande cash acceptée)
     *   tout        → cancelled   (annulation)
     *
     * Chaque changement déclenche automatiquement un SMS au client.
     */
    #[Route('/{id}/status', name: 'update_status', methods: ['PATCH'])]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $order = $this->orderRepository->findOneWithDetails($id);
        if (!$order) return $this->notFound('Commande introuvable.');

        $body      = $this->getJsonBody($request);
        $newStatus = $body['status'] ?? null;
        $note      = $body['note']   ?? null;

        if (!$newStatus) {
            return $this->error('Le champ "status" est obligatoire.', Response::HTTP_BAD_REQUEST);
        }

        $statusChanged = $order->getStatus() !== $newStatus;

        if ($statusChanged) {
            // Vérifie que la transition est autorisée
            $validationError = $this->validateTransition($order->getStatus(), $newStatus);
            if ($validationError) {
                return $this->error($validationError, Response::HTTP_BAD_REQUEST);
            }

            // Cas spécial — annulation : restaure le stock
            if ($newStatus === Order::STATUS_CANCELLED) {
                try {
                    $this->orderService->cancelOrder($order);
                } catch (\DomainException $e) {
                    return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
                }
            } else {
                $order->setStatus($newStatus);
            }
        }

        // Ajoute la note interne si fournie
        if ($note) {
            $existingNotes = $order->getAdminNotes() ?? '';
            $timestamp     = (new \DateTimeImmutable())->format('d/m/Y H:i');
            $order->setAdminNotes(
                $existingNotes
                    ? $existingNotes . "\n[{$timestamp}] " . $note
                    : "[{$timestamp}] " . $note
            );
        }

        $this->em->flush();

        if ($statusChanged) {
            // SMS automatique au client à chaque changement de statut
            $this->smsService->notifyClientStatusUpdate($order);
        }

        return $this->success([
            'id'          => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'status'      => $order->getStatus(),
            'statusLabel' => $order->getStatusLabel(),
        ], $statusChanged ? 'Statut mis à jour. SMS envoyé au client.' : 'Note enregistrée.');
    }

    // ── PATCH /api/admin/orders/{id}/notes ───────────────────────────────
    /**
     * Ajoute ou remplace les notes internes d'une commande.
     * Ces notes sont visibles uniquement par l'équipe admin, jamais par le client.
     *
     * Body JSON :
     * { "notes": "Client contacté le 25/06. Relance prévue demain." }
     */
    #[Route('/{id}/notes', name: 'update_notes', methods: ['PATCH'])]
    public function updateNotes(int $id, Request $request): JsonResponse
    {
        $order = $this->orderRepository->find($id);
        if (!$order) return $this->notFound('Commande introuvable.');

        $body  = $this->getJsonBody($request);
        $notes = $body['notes'] ?? null;

        if ($notes === null) {
            return $this->error('Le champ "notes" est obligatoire.', Response::HTTP_BAD_REQUEST);
        }

        $order->setAdminNotes($notes);
        $this->em->flush();

        return $this->success(null, 'Notes internes mises à jour.');
    }

    // ── PATCH /api/admin/orders/{id}/confirm-cod ─────────────────────────
    /**
     * Confirme manuellement une commande à paiement Cash On Delivery.
     * Utilisé quand le livreur a remis le paiement à l'admin.
     *
     * Passe le statut de pending_cod → confirmed
     * Et déclenche le SMS de confirmation au client.
     */
    #[Route('/{id}/confirm-cod', name: 'confirm_cod', methods: ['PATCH'])]
    public function confirmCod(int $id): JsonResponse
    {
        $order = $this->orderRepository->findOneWithDetails($id);
        if (!$order) return $this->notFound('Commande introuvable.');

        if ($order->getStatus() !== Order::STATUS_PENDING_COD) {
            return $this->error(
                'Cette commande n\'est pas en attente de paiement à la livraison.',
                Response::HTTP_BAD_REQUEST
            );
        }

        // Confirme la commande et met à jour les statistiques de vente
        $this->orderService->confirmOrder($order);

        // SMS au client — sa commande est maintenant officiellement confirmée
        $this->smsService->notifyClientOrderConfirmed($order);

        return $this->success([
            'id'          => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'status'      => $order->getStatus(),
            'statusLabel' => $order->getStatusLabel(),
        ], 'Commande confirmée. SMS envoyé au client.');
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    /**
     * Vérifie que la transition de statut est logiquement valide.
     * Retourne un message d'erreur si invalide, null si OK.
     */
    private function validateTransition(string $current, string $new): ?string
    {
        // Statuts autorisés pour chaque statut courant
        $allowed = [
            Order::STATUS_CONFIRMED    => [Order::STATUS_PROCESSING, Order::STATUS_CANCELLED],
            Order::STATUS_PROCESSING   => [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED],
            Order::STATUS_SHIPPED      => [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED],
            Order::STATUS_PENDING_COD  => [Order::STATUS_CONFIRMED, Order::STATUS_CANCELLED],
            Order::STATUS_DELIVERED    => [], // statut final — aucune transition possible
            Order::STATUS_CANCELLED    => [], // statut final
            Order::STATUS_PAYMENT_FAILED => [Order::STATUS_CANCELLED],
        ];

        if (!isset($allowed[$current])) {
            return "Le statut actuel '{$current}' ne peut pas être modifié.";
        }

        if (!in_array($new, $allowed[$current], true)) {
            $validOptions = implode(', ', $allowed[$current]);
            return "Transition invalide : '{$current}' → '{$new}'. "
                . ($validOptions ? "Options valides : {$validOptions}." : "Aucune transition possible.");
        }

        return null;
    }

    /**
     * Format court pour la liste des commandes.
     */
    private function formatForList(Order $order): array
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
            'itemsCount'    => $order->getItems()->count(),
            'createdAt'     => $order->getCreatedAt()->format('d/m/Y H:i'),
            'client'        => $address ? [
                'fullName' => $address->getFullName(),
                'phone'    => $address->getPhone(),
                'city'     => $address->getCity(),
            ] : null,
            'paymentStatus' => $payment?->getStatus(),
            'isCod'         => $order->isCashOnDelivery(),
        ];
    }

    /**
     * Format complet pour le détail d'une commande en back-office.
     */
    private function formatForAdmin(Order $order): array
    {
        $address = $order->getDeliveryAddress();
        $payment = $order->getLatestPayment();

        return [
            'id'             => $order->getId(),
            'orderNumber'    => $order->getOrderNumber(),
            'status'         => $order->getStatus(),
            'statusLabel'    => $order->getStatusLabel(),
            'paymentMethod'  => $order->getPaymentMethod(),
            'itemsTotal'     => $order->getItemsTotal(),
            'deliveryFee'    => $order->getDeliveryFee(),
            'paymentFee'     => $order->getPaymentFee(),
            'discountAmount' => $order->getDiscountAmount(),
            'totalAmount'    => $order->getTotalAmount(),
            'promoCodeUsed'  => $order->getPromoCodeUsed(),
            'adminNotes'     => $order->getAdminNotes(),
            'createdAt'      => $order->getCreatedAt()->format('d/m/Y H:i:s'),

            // Transitions disponibles depuis le statut actuel
            'availableTransitions' => $this->getAvailableTransitions($order->getStatus()),

            // Articles commandés
            'items' => array_map(fn($item) => [
                'productName'  => $item->getProductName(),
                'variantLabel' => $item->getVariantLabel(),
                'imageUrl'     => $item->getProductImageUrl(),
                'quantity'     => $item->getQuantity(),
                'unitPrice'    => $item->getUnitPrice(),
                'subtotal'     => $item->getSubtotal(),
            ], $order->getItems()->toArray()),

            // Adresse de livraison
            'deliveryAddress' => $address ? [
                'fullName'     => $address->getFullName(),
                'phone'        => $address->getPhone(),
                'city'         => $address->getCity(),
                'district'     => $address->getDistrict(),
                'street'       => $address->getStreet(),
                'instructions' => $address->getInstructions(),
                'formatted'    => $address->getFormattedAddress(),
            ] : null,

            // Paiement
            'payment' => $payment ? [
                'provider'       => $payment->getProvider(),
                'providerLabel'  => $payment->getProviderLabel(),
                'amount'         => $payment->getAmount(),
                'status'         => $payment->getStatus(),
                'depositId'      => $payment->getDepositId(),
                'failureCode'    => $payment->getFailureCode(),
                'failureMessage' => $payment->getFailureMessage(),
                'confirmedAt'    => $payment->getConfirmedAt()?->format('d/m/Y H:i:s'),
            ] : null,

            // Client
            'user' => $order->getUser() ? [
                'id'       => $order->getUser()->getId(),
                'fullName' => $order->getUser()->getFullName(),
                'phone'    => $order->getUser()->getPhone(),
            ] : null,
        ];
    }

    /**
     * Retourne les transitions disponibles depuis un statut.
     * Utilisé par React pour afficher les boutons d'action corrects.
     */
    private function getAvailableTransitions(string $status): array
    {
        $transitions = [
            Order::STATUS_CONFIRMED   => [
                ['status' => Order::STATUS_PROCESSING, 'label' => 'Commencer la préparation'],
                ['status' => Order::STATUS_CANCELLED,  'label' => 'Annuler la commande'],
            ],
            Order::STATUS_PROCESSING  => [
                ['status' => Order::STATUS_SHIPPED,   'label' => 'Marquer comme expédiée'],
                ['status' => Order::STATUS_CANCELLED, 'label' => 'Annuler la commande'],
            ],
            Order::STATUS_SHIPPED     => [
                ['status' => Order::STATUS_DELIVERED, 'label' => 'Marquer comme livrée'],
                ['status' => Order::STATUS_CANCELLED, 'label' => 'Annuler la commande'],
            ],
            Order::STATUS_PENDING_COD => [
                ['status' => Order::STATUS_CONFIRMED, 'label' => 'Confirmer la commande'],
                ['status' => Order::STATUS_CANCELLED, 'label' => 'Annuler la commande'],
            ],
            Order::STATUS_DELIVERED   => [],
            Order::STATUS_CANCELLED   => [],
        ];

        return $transitions[$status] ?? [];
    }

    /**
     * Calcule les statistiques rapides par statut.
     * Affiché sous forme de badges dans la liste des commandes admin.
     */
    private function computeStats(array $orders): array
    {
        $stats = [
            'pending_payment' => 0,
            'confirmed'       => 0,
            'processing'      => 0,
            'shipped'         => 0,
            'delivered'       => 0,
            'cancelled'       => 0,
            'pending_cod'     => 0,
            'payment_failed'  => 0,
        ];

        foreach ($orders as $order) {
            $status = $order->getStatus();
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $stats;
    }
}