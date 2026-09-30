<?php

namespace App\Controller\Api\Admin;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
/**
 * AdminClientController — Donald Gros E-commerce
 *
 * Tous les endpoints back-office pour la gestion des clients.
 * Sécurisé par ROLE_ADMIN (firewall admin dans security.yaml).
 *
 * Routes :
 *   GET    /api/admin/clients           → liste paginée avec filtres
 *   GET    /api/admin/clients/{id}      → fiche complète + commandes
 *   PATCH  /api/admin/clients/{id}/block   → bloquer un compte
 *   PATCH  /api/admin/clients/{id}/unblock → débloquer un compte
 */
#[Route('/api/admin/clients', name: 'api_admin_clients_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminClientController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}
    // ─── Helpers ─────────────────────────────────────────────────────────────
    /** Réponse JSON enveloppée dans le format standard { success, message, data } */
    private function ok(mixed $data, string $message = 'OK', int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }
    private function fail(string $message, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
        ], $status);
    }
    /** Sérialise un User en item de liste (sans les commandes) */
    private function serializeListItem(User $user): array
    {
        return [
            'id'          => $user->getId(),
            'firstName'   => $user->getFirstName(),
            'lastName'    => $user->getLastName(),
            'fullName'    => trim($user->getFirstName() . ' ' . $user->getLastName()),
            'phone'       => $user->getPhone(),
            'city'        => $user->getCity(),
            'status'      => $user->getStatus(),
            'createdAt'   => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'ordersCount' => $user->getOrders()->count(),
            'totalSpent'  => $this->computeTotalSpent($user),
        ];
    }
    /** Sérialise un User en fiche complète avec commandes */
    private function serializeDetail(User $user): array
    {
        $orders = [];
        foreach ($user->getOrders() as $order) {
            $orders[] = [
                'id'          => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'status'      => $order->getStatus(),
                'statusLabel' => $this->statusLabel($order->getStatus()),
                'totalAmount' => $order->getTotalAmount(),
                'createdAt'   => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }
        // Les commandes les plus récentes en premier
        usort($orders, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));
        return [
            'id'          => $user->getId(),
            'firstName'   => $user->getFirstName(),
            'lastName'    => $user->getLastName(),
            'fullName'    => trim($user->getFirstName() . ' ' . $user->getLastName()),
            'phone'       => $user->getPhone(),
            'city'        => $user->getCity(),
            'status'      => $user->getStatus(),
            'createdAt'   => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'ordersCount' => count($orders),
            'totalSpent'  => $this->computeTotalSpent($user),
            'orders'      => $orders,
        ];
    }
    /** Calcule le total dépensé par le client (commandes livrées/confirmées) */
    private function computeTotalSpent(User $user): float
    {
        $total = 0.0;
        $paidStatuses = ['confirmed', 'processing', 'shipped', 'delivered'];
        foreach ($user->getOrders() as $order) {
            if (in_array($order->getStatus(), $paidStatuses, true)) {
                $total += (float) $order->getTotalAmount();
            }
        }
        return $total;
    }
    /** Libellé FR pour un statut de commande */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_payment' => 'En attente de paiement',
            'payment_failed'  => 'Paiement échoué',
            'confirmed'       => 'Confirmée',
            'processing'      => 'En préparation',
            'shipped'         => 'Expédiée',
            'delivered'       => 'Livrée',
            'cancelled'       => 'Annulée',
            'pending_cod'     => 'Paiement à la livraison',
            default           => ucfirst($status),
        };
    }
    // ─── Endpoints ───────────────────────────────────────────────────────────
    /**
     * GET /api/admin/clients
     *
     * Query params :
     *   search  (string)          → filtre nom / prénom / téléphone
     *   status  (active|blocked)  → filtre par statut
     *   city    (string)          → filtre par ville (LIKE)
     *   page    (int, défaut 1)
     *   limit   (int, défaut 15, max 100)
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->query->get('search'),
            'status' => $request->query->get('status'),
            'city'   => $request->query->get('city'),
        ];
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 15)));
        // Récupère tous les clients filtrés (le Repository fait le filtre)
        $all = $this->userRepository->findAllForAdmin($filters);
        $total      = count($all);
        $totalPages = (int) ceil($total / $limit);
        $offset     = ($page - 1) * $limit;
        $paginated  = array_slice($all, $offset, $limit);
        return $this->ok([
            'clients'    => array_map([$this, 'serializeListItem'], $paginated),
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => max(1, $totalPages),
        ]);
    }
    /**
     * GET /api/admin/clients/{id}
     *
     * Fiche complète du client avec l'historique de ses commandes.
     */
    #[Route('/{id}', name: 'detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): JsonResponse
    {
        $user = $this->userRepository->findOneWithOrders($id);
        if (!$user) {
            return $this->fail('Client introuvable.', Response::HTTP_NOT_FOUND);
        }
        return $this->ok($this->serializeDetail($user));
    }
    /**
     * PATCH /api/admin/clients/{id}/block
     *
     * Bloque le compte d'un client (status → blocked).
     * Idempotent : si déjà bloqué, retourne 200 sans erreur.
     */
    #[Route('/{id}/block', name: 'block', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function block(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            return $this->fail('Client introuvable.', Response::HTTP_NOT_FOUND);
        }
        // Empêche de bloquer un admin
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->fail('Impossible de bloquer un compte administrateur.', Response::HTTP_FORBIDDEN);
        }
        $user->setStatus(User::STATUS_BLOCKED);
        $this->userRepository->save($user, flush: true);
        return $this->ok([
            'id'     => $user->getId(),
            'status' => $user->getStatus(),
        ], 'Compte bloqué avec succès.');
    }
    /**
     * PATCH /api/admin/clients/{id}/unblock
     *
     * Débloque un compte client (status → active).
     * Idempotent : si déjà actif, retourne 200 sans erreur.
     */
    #[Route('/{id}/unblock', name: 'unblock', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function unblock(int $id): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            return $this->fail('Client introuvable.', Response::HTTP_NOT_FOUND);
        }
        $user->setStatus(User::STATUS_ACTIVE);
        $this->userRepository->save($user, flush: true);
        return $this->ok([
            'id'     => $user->getId(),
            'status' => $user->getStatus(),
        ], 'Compte débloqué avec succès.');
    }
}