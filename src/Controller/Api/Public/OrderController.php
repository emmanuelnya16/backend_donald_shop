<?php

namespace App\Controller\Api\Public;

use App\Controller\Api\AbstractApiController;
use App\DTO\Order\CreateOrderDTO;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Service\Order\OrderPaymentOrchestrator;
use App\Service\Order\OrderService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/orders', name: 'api_orders_')]
class OrderController extends AbstractApiController
{
    public function __construct(
        private readonly OrderPaymentOrchestrator $orchestrator,
        private readonly OrderService             $orderService,
        private readonly OrderRepository          $orderRepository,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── POST /api/orders ───────────────────────────────────────────────────
    /**
     * Crée une nouvelle commande. Couvre les deux chemins du tunnel :
     *
     * Chemin A — Payer Maintenant
     * {
     *   "items": [{ "variantId": 10, "quantity": 2 }],
     *   "paymentMethod": "mtn_momo",
     *   "payerPhone": "+237699123456",
     *   "promoCode": "DG2025",
     *   "deliveryAddress": {
     *     "firstName": "Jean", "lastName": "Dupont",
     *     "phone": "+237699123456", "city": "Douala",
     *     "district": "Akwa", "street": "Rue 1234",
     *     "instructions": "Portail rouge"
     *   }
     * }
     *
     * Chemin B — Payer à la Livraison
     * {
     *   "items": [{ "variantId": 10, "quantity": 2 }],
     *   "paymentMethod": "cash_on_delivery",
     *   "deliveryAddress": { ... }
     * }
     *
     * Réponse 201 (mobile) :
     * {
     *   "success": true,
     *   "data": {
     *     "order": { "id": 42, "orderNumber": "DG-2025-0042", ... },
     *     "requiresPolling": true
     *   }
     * }
     *
     * Réponse 201 (cash) :
     * {
     *   "success": true,
     *   "data": {
     *     "order": { ... },
     *     "requiresPolling": false
     *   }
     * }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // Parsing manuel : le sérialiseur Symfony ne gère pas automatiquement
        // les objets imbriqués (items[] → OrderItemInputDTO, deliveryAddress → DeliveryAddressDTO)
        $content = $request->getContent();
        if (empty($content)) {
            return $this->error('Corps de la requête JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('Corps de la requête JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $dto = CreateOrderDTO::fromArray($data);

        $errors = $this->validate($dto);
        if ($errors) {
            return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);
        }

        // Validation conditionnelle : numéro de téléphone obligatoire si paiement mobile
        if ($dto->isMobilePayment() && empty($dto->payerPhone)) {
            return $this->error(
                'Le numéro de téléphone est obligatoire pour le paiement mobile.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['payerPhone' => ['Ce champ est obligatoire pour MTN MoMo ou Orange Money.']]
            );
        }

        // Récupère l'utilisateur connecté si présent (commande possible sans compte)
        $user = $this->getUser() instanceof User ? $this->getUser() : null;

        try {
            $result = $this->orchestrator->createOrderWithPayment($dto, $user);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->created([
            'order'           => $this->orderService->formatOne($result['order']),
            'requiresPolling' => $result['requiresPolling'],
        ], 'Commande créée avec succès.');
    }

    // ── GET /api/orders/{id} ───────────────────────────────────────────────
    /**
     * Détail d'une commande — utilisé sur la page de confirmation
     * et la page de détail commande dans l'espace client.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $order = $this->orderRepository->findOneWithDetails($id);
        if (!$order) return $this->notFound('Commande introuvable.');

        // Sécurité : si la commande appartient à un compte, seul ce compte
        // (ou un visiteur anonyme pour les commandes sans compte) peut la voir
        $currentUser = $this->getUser();
        if ($order->getUser() && (!$currentUser || $order->getUser()->getId() !== $currentUser->getId())) {
            return $this->forbidden('Vous n\'avez pas accès à cette commande.');
        }

        return $this->success($this->orderService->formatOne($order));
    }

    // ── GET /api/orders/by-number/{orderNumber} ───────────────────────────
    /**
     * Recherche d'une commande par son numéro lisible.
     * Utilisé pour la page de suivi de commande accessible sans compte.
     *
     * Ex : GET /api/orders/by-number/DG-2025-0042
     */
    #[Route('/by-number/{orderNumber}', name: 'show_by_number', methods: ['GET'])]
    public function showByNumber(string $orderNumber): JsonResponse
    {
        $order = $this->orderRepository->findByOrderNumber($orderNumber);
        if (!$order) return $this->notFound('Commande introuvable.');

        return $this->success($this->orderService->formatOne($order));
    }

    // ── GET /api/user/orders ───────────────────────────────────────────────
    /**
     * Historique des commandes du client connecté.
     * Query params : ?page=1&limit=10
     */
    #[Route('/me/history', name: 'my_orders', methods: ['GET'])]
    public function myOrders(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) return $this->unauthorized();

        $page  = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 10);

        $result = $this->orderRepository->findByUser($user, $page, $limit);

        return $this->success([
            'orders' => array_map(fn($o) => $this->orderService->formatOne($o), $result['orders']),
            'total'  => $result['total'],
            'page'   => $result['page'],
            'limit'  => $result['limit'],
        ]);
    }
}