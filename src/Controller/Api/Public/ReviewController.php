<?php

namespace App\Controller\Api\Public;

use App\Controller\Api\AbstractApiController;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ReviewController extends AbstractApiController
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

    // ── GET /api/products/{slug}/reviews ──────────────────────────────────
    /**
     * Retourne les avis publiés d'un produit.
     * Accessible sans connexion — tout visiteur peut lire les avis.
     *
     * Query params :
     *   ?page=1&limit=10
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "reviews": [...],
     *     "total": 28,
     *     "averageRating": 4.3,
     *     "distribution": { "5": 18, "4": 6, "3": 2, "2": 1, "1": 1 },
     *     "page": 1,
     *     "totalPages": 3
     *   }
     * }
     */
    #[Route('/api/products/{slug}/reviews', name: 'api_product_reviews', methods: ['GET'])]
    public function listByProduct(string $slug, Request $request): JsonResponse
    {
        $product = $this->productRepository->findBySlug($slug);
        if (!$product) return $this->notFound('Produit introuvable.');

        $page  = max(1, $request->query->getInt('page', 1));
        $limit = min(20, $request->query->getInt('limit', 10));
        $offset = ($page - 1) * $limit;

        $reviews      = $this->reviewRepository->findPublishedByProduct($product->getId(), $limit, $offset);
        $total        = $this->reviewRepository->countPublishedByProduct($product->getId());
        $stats        = $this->reviewRepository->computeStats($product->getId());
        $distribution = $this->reviewRepository->getRatingDistribution($product->getId());

        return $this->success([
            'reviews'       => array_map(fn($r) => $this->formatReview($r), $reviews),
            'total'         => $total,
            'averageRating' => $stats['averageRating'],
            'distribution'  => $distribution,
            'page'          => $page,
            'limit'         => $limit,
            'totalPages'    => (int) ceil($total / $limit),
        ]);
    }

    // ── POST /api/reviews ─────────────────────────────────────────────────
    /**
     * Le client connecté soumet un avis sur un produit qu'il a acheté.
     *
     * Règles :
     *  - Client doit être connecté (JWT requis)
     *  - Client doit avoir acheté le produit (commande confirmée/livrée)
     *  - Un seul avis par produit par client
     *
     * Body JSON :
     * {
     *   "productId": 42,
     *   "rating": 5,
     *   "title": "Excellent produit",
     *   "body": "Très bonne qualité, je recommande."
     * }
     *
     * Réponse 201 :
     * {
     *   "success": true,
     *   "message": "Votre avis a été soumis et sera publié après modération.",
     *   "data": { "id": 12, "status": "pending" }
     * }
     */
    #[Route('/api/reviews', name: 'api_reviews_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) return $this->unauthorized('Vous devez être connecté pour laisser un avis.');

        $body = $this->getJsonBody($request);
        if (!$body) return $this->error('Corps JSON invalide.', Response::HTTP_BAD_REQUEST);

        $productId = (int) ($body['productId'] ?? 0);
        $rating    = (int) ($body['rating']    ?? 0);
        $title     = trim($body['title']       ?? '');
        $reviewBody= trim($body['body']        ?? '');

        // Validations métier
        if (!$productId) {
            return $this->error('Le produit est obligatoire.', Response::HTTP_BAD_REQUEST);
        }

        $product = $this->productRepository->find($productId);
        if (!$product) return $this->notFound('Produit introuvable.');

        if ($rating < 1 || $rating > 5) {
            return $this->error('La note doit être entre 1 et 5.', Response::HTTP_BAD_REQUEST);
        }

        if (strlen($reviewBody) < 10) {
            return $this->error('L\'avis doit contenir au moins 10 caractères.', Response::HTTP_BAD_REQUEST);
        }

        // Vérifie que le client a bien acheté ce produit
        if (!$this->reviewRepository->hasUserPurchasedProduct($user->getId(), $productId)) {
            return $this->error(
                'Vous ne pouvez laisser un avis que sur des produits que vous avez achetés.',
                Response::HTTP_FORBIDDEN
            );
        }

        // Vérifie qu'il n'a pas déjà laissé un avis sur ce produit
        if ($this->reviewRepository->hasUserReviewedProduct($user->getId(), $productId)) {
            return $this->error(
                'Vous avez déjà laissé un avis sur ce produit.',
                Response::HTTP_CONFLICT
            );
        }

        // Crée l'avis — statut "pending" par défaut (modération admin requise)
        $review = new Review();
        $review->setUser($user);
        $review->setProduct($product);
        $review->setRating($rating);
        $review->setTitle($title ?: null);
        $review->setBody($reviewBody);
        $review->setStatus(Review::STATUS_PENDING);

        // Snapshot de la variante achetée pour affichage dans l'avis
        // On récupère la variante de la commande confirmée la plus récente
        $purchasedVariant = $this->getPurchasedVariantLabel($user->getId(), $productId);
        $review->setPurchasedVariant($purchasedVariant);

        $this->em->persist($review);
        $this->em->flush();

        return $this->created([
            'id'     => $review->getId(),
            'status' => $review->getStatus(),
        ], 'Votre avis a été soumis et sera publié après modération. Merci !');
    }

    // ── POST /api/reviews/{id}/helpful ────────────────────────────────────
    /**
     * Vote "utile" sur un avis — accessible sans connexion.
     * Incrémente le compteur helpfulVotes.
     */
    #[Route('/api/reviews/{id}/helpful', name: 'api_reviews_helpful', methods: ['POST'])]
    public function markHelpful(int $id): JsonResponse
    {
        $review = $this->reviewRepository->find($id);
        if (!$review || !$review->isPublished()) {
            return $this->notFound('Avis introuvable.');
        }

        $review->incrementHelpfulVotes();
        $this->em->flush();

        return $this->success(['helpfulVotes' => $review->getHelpfulVotes()]);
    }

    // ── Formatage ─────────────────────────────────────────────────────────

    private function formatReview(Review $review): array
    {
        $user = $review->getUser();

        return [
            'id'              => $review->getId(),
            'rating'          => $review->getRating(),
            'title'           => $review->getTitle(),
            'body'            => $review->getBody(),
            'helpfulVotes'    => $review->getHelpfulVotes(),
            'purchasedVariant'=> $review->getPurchasedVariant(),
            'createdAt'       => $review->getCreatedAt()->format('d/m/Y'),
            // Anonymisation partielle : prénom + initiale du nom
            'author'          => $user ? [
                'name'   => $user->getFirstName() . ' ' . substr($user->getLastName(), 0, 1) . '.',
                'initials' => strtoupper(substr($user->getFirstName(), 0, 1) . substr($user->getLastName(), 0, 1)),
            ] : ['name' => 'Client anonyme', 'initials' => 'CA'],
        ];
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    /**
     * Récupère le label de la variante achetée pour le snapshot de l'avis.
     * Ex : "Taille M — Bleu Marine"
     */
    private function getPurchasedVariantLabel(int $userId, int $productId): ?string
    {
        try {
            $result = $this->em->createQuery(
                'SELECT v.size, v.color
                 FROM App\Entity\OrderItem oi
                 JOIN oi.productVariant v
                 JOIN oi.order o
                 JOIN v.product p
                 WHERE o.user = :userId
                 AND p.id = :productId
                 AND o.status IN (:statuses)
                 ORDER BY o.createdAt DESC'
            )
            ->setParameter('userId', $userId)
            ->setParameter('productId', $productId)
            ->setParameter('statuses', ['confirmed', 'processing', 'shipped', 'delivered'])
            ->setMaxResults(1)
            ->getOneOrNullResult();

            if (!$result) return null;

            $parts = array_filter([$result['size'] ? 'Taille '.$result['size'] : null, $result['color']]);
            return implode(' — ', $parts) ?: null;

        } catch (\Exception) {
            return null;
        }
    }
}