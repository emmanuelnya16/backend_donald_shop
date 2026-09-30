<?php

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\DTO\Catalogue\ProductDTO;
use App\Repository\ProductRepository;
use App\Service\Catalogue\ProductService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/products', name: 'api_admin_products_')]
#[IsGranted('ROLE_ADMIN')]
class AdminProductController extends AbstractApiController
{
    public function __construct(
        private readonly ProductService    $productService,
        private readonly ProductRepository $productRepository,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── GET /api/admin/products ──────────────────────────────────────────
    // Params optionnels : ?status=active&categoryId=1&search=chemise
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $products = $this->productRepository->findForAdmin([
            'status'     => $request->query->get('status'),
            'categoryId' => $request->query->get('categoryId'),
            'search'     => $request->query->get('search'),
        ]);

        return $this->success(
            array_map(
                fn($p) => $this->productService->formatOne($p),
                $products
            )
        );
    }

    // ── GET /api/admin/products/{id} ─────────────────────────────────────
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $product = $this->productRepository->find($id);
        if (!$product) return $this->notFound('Produit introuvable.');
        return $this->success($this->productService->formatOne($product));
    }

    // ── POST /api/admin/products ─────────────────────────────────────────
    /**
     * Crée un nouveau produit avec ses variantes.
     *
     * Body JSON :
     * {
     *   "name": "Chemise Homme Bleu",
     *   "categoryId": 1,
     *   "basePrice": 15000,
     *   "shortDescription": "Chemise en coton...",
     *   "status": "draft",
     *   "variants": [
     *     { "size": "M", "color": "Bleu", "colorHex": "#1A56DB", "stock": 10 },
     *     { "size": "L", "color": "Bleu", "colorHex": "#1A56DB", "stock": 5 }
     *   ]
     * }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, ProductDTO::class);
        if (!$dto) return $this->error('JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        try {
            $product = $this->productService->create($dto);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->created($this->productService->formatOne($product), 'Produit créé.');
    }

    // ── PUT /api/admin/products/{id} ─────────────────────────────────────
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $product = $this->productRepository->find($id);
        if (!$product) return $this->notFound('Produit introuvable.');

        $dto = $this->deserialize($request, ProductDTO::class);
        if (!$dto) return $this->error('JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        try {
            $product = $this->productService->update($product, $dto);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->success($this->productService->formatOne($product), 'Produit mis à jour.');
    }

    // ── PATCH /api/admin/products/{id}/publish ───────────────────────────
    #[Route('/{id}/publish', name: 'publish', methods: ['PATCH'])]
    public function publish(int $id): JsonResponse
    {
        $product = $this->productRepository->find($id);
        if (!$product) return $this->notFound('Produit introuvable.');

        $this->productService->publish($product);
        return $this->success(null, 'Produit publié et visible sur le site.');
    }

    // ── PATCH /api/admin/products/{id}/archive ───────────────────────────
    #[Route('/{id}/archive', name: 'archive', methods: ['PATCH'])]
    public function archive(int $id): JsonResponse
    {
        $product = $this->productRepository->find($id);
        if (!$product) return $this->notFound('Produit introuvable.');

        $this->productService->archive($product);
        return $this->success(null, 'Produit archivé.');
    }
}