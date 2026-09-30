<?php

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\Entity\Review;
use App\Repository\ReviewRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/reviews', name: 'api_admin_reviews_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminReviewController extends AbstractApiController
{
    public function __construct(
        private readonly ReviewRepository       $reviewRepository,
        private readonly ProductRepository      $productRepository,
        private readonly EntityManagerInterface $em,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── GET /api/admin/reviews ─────────────────────────────────────────────
    /**
     * File de modération — liste tous les avis avec filtres.
     *
     * Query params :
     *   ?status=pending       → avis en attente (défaut)
     *   ?status=published     → avis publiés
     *   ?status=rejected      → avis rejetés
     *   ?productId=42         → avis d'un produit spécifique
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "reviews": [...],
     *     "total": 12,
     *     "pendingCount": 5   ← badge dans la sidebar admin
     *   }
     * }
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $reviews = $this->reviewRepository->findForAdmin([
            'status'    => $request->query->get('status', Review::STATUS_PENDING),
            'productId' => $request->query->get('productId'),
        ]);

        // Compte global des avis en attente pour le badge sidebar
        $pendingCount = $this->reviewRepository->countPending();

        return $this->success([
            'reviews'      => array_map(fn($r) => $this->formatForAdmin($r), $reviews),
            'total'        => count($reviews),
            'pendingCount' => $pendingCount,
        ]);
    }

    // ── GET /api/admin/reviews/{id} ────────────────────────────────────────
    /**
     * Détail d'un avis individuel.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $review = $this->reviewRepository->find($id);
        if (!$review) return $this->notFound('Avis introuvable.');

        return $this->success($this->formatForAdmin($review));
    }

    // ── PATCH /api/admin/reviews/{id}/publish ─────────────────────────────
    /**
     * Publie un avis — il devient visible sur la fiche produit publique.
     * Met également à jour la note moyenne du produit concerné.
     *
     * Réponse :
     * {
     *   "success": true,
     *   "message": "Avis publié.",
     *   "data": { "id": 5, "status": "published" }
     * }
     */
    #[Route('/{id}/publish', name: 'publish', methods: ['PATCH'])]
    public function publish(int $id): JsonResponse
    {
        $review = $this->reviewRepository->find($id);
        if (!$review) return $this->notFound('Avis introuvable.');

        if ($review->isPublished()) {
            return $this->error('Cet avis est déjà publié.', Response::HTTP_BAD_REQUEST);
        }

        $review->publish();
        $this->em->flush();

        // Recalcule la note moyenne et le nombre d'avis du produit
        $this->updateProductStats($review->getProduct()->getId());

        return $this->success([
            'id'     => $review->getId(),
            'status' => $review->getStatus(),
        ], 'Avis publié. Il est maintenant visible sur la fiche produit.');
    }

    // ── PATCH /api/admin/reviews/{id}/reject ──────────────────────────────
    /**
     * Rejette un avis — il reste invisible sur le site public.
     *
     * Body JSON (optionnel) :
     * { "reason": "Contenu inapproprié" }
     */
    #[Route('/{id}/reject', name: 'reject', methods: ['PATCH'])]
    public function reject(int $id, Request $request): JsonResponse
    {
        $review = $this->reviewRepository->find($id);
        if (!$review) return $this->notFound('Avis introuvable.');

        if ($review->isRejected()) {
            return $this->error('Cet avis est déjà rejeté.', Response::HTTP_BAD_REQUEST);
        }

        $body   = $this->getJsonBody($request);
        $reason = $body['reason'] ?? null;

        $review->reject($reason);
        $this->em->flush();

        // Si l'avis était publié avant d'être rejeté → recalcule les stats produit
        $this->updateProductStats($review->getProduct()->getId());

        return $this->success([
            'id'     => $review->getId(),
            'status' => $review->getStatus(),
        ], 'Avis rejeté.');
    }

    // ── DELETE /api/admin/reviews/{id} ────────────────────────────────────
    /**
     * Supprime définitivement un avis.
     * Réservé au Super Admin.
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $review = $this->reviewRepository->find($id);
        if (!$review) return $this->notFound('Avis introuvable.');

        $productId = $review->getProduct()->getId();

        $this->reviewRepository->remove($review, flush: true);

        // Recalcule les stats produit après suppression
        $this->updateProductStats($productId);

        return $this->success(null, 'Avis supprimé définitivement.');
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    /**
     * Met à jour averageRating et reviewCount sur le produit
     * après chaque publication ou rejet d'avis.
     */
    private function updateProductStats(int $productId): void
    {
        $product = $this->productRepository->find($productId);
        if (!$product) return;

        $stats = $this->reviewRepository->computeStats($productId);

        $product->setAverageRating($stats['averageRating']);
        $product->setReviewCount($stats['reviewCount']);
        $this->em->flush();
    }

    /**
     * Format complet d'un avis pour le back-office admin.
     * Contient toutes les infos dont l'admin a besoin pour modérer.
     */
    private function formatForAdmin(Review $review): array
    {
        $user    = $review->getUser();
        $product = $review->getProduct();

        return [
            'id'              => $review->getId(),
            'rating'          => $review->getRating(),
            'title'           => $review->getTitle(),
            'body'            => $review->getBody(),
            'status'          => $review->getStatus(),
            'rejectionReason' => $review->getRejectionReason(),
            'purchasedVariant'=> $review->getPurchasedVariant(),
            'helpfulVotes'    => $review->getHelpfulVotes(),
            'createdAt'       => $review->getCreatedAt()->format('d/m/Y H:i'),
            'moderatedAt'     => $review->getModeratedAt()?->format('d/m/Y H:i'),

            // Auteur complet (pas d'anonymisation en back-office)
            'author' => $user ? [
                'id'       => $user->getId(),
                'fullName' => $user->getFullName(),
                'phone'    => $user->getPhone(),
            ] : null,

            // Produit concerné
            'product' => [
                'id'   => $product->getId(),
                'name' => $product->getName(),
                'slug' => $product->getSlug(),
            ],
        ];
    }
}