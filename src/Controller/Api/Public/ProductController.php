<?php

namespace App\Controller\Api\Public;

use App\Controller\Api\AbstractApiController;
use App\Repository\ProductRepository;
use App\Service\Catalogue\ProductService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/products', name: 'api_public_products_')]
class ProductController extends AbstractApiController
{
    public function __construct(
        private readonly ProductService    $productService,
        private readonly ProductRepository $productRepository,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── GET /api/products ─────────────────────────────────────────────────
    /**
     * Liste paginée des produits avec filtres.
     * C'est l'endpoint principal de la page catalogue.
     *
     * Query params disponibles :
     *   ?categorySlug=homme
     *   ?categoryId=1
     *   ?minPrice=5000
     *   ?maxPrice=50000
     *   ?sizes[]=S&sizes[]=M
     *   ?colors[]=Bleu&colors[]=Rouge
     *   ?inStockOnly=1
     *   ?minRating=4
     *   ?search=chemise
     *   ?sortBy=price_asc        (price_asc|price_desc|newest|best_sellers|top_rated)
     *   ?page=1
     *   ?limit=20
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": {
     *     "products": [ { carte produit... }, ... ],
     *     "total": 124,
     *     "page": 1,
     *     "limit": 20,
     *     "totalPages": 7
     *   }
     * }
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $result = $this->productRepository->findFiltered([
            'categorySlug' => $request->query->get('categorySlug'),
            'categoryId'   => $request->query->get('categoryId'),
            'minPrice'     => $request->query->get('minPrice'),
            'maxPrice'     => $request->query->get('maxPrice'),
            'sizes'        => $request->query->all('sizes'),
            'colors'       => $request->query->all('colors'),
            'inStockOnly'  => $request->query->getBoolean('inStockOnly'),
            'minRating'    => $request->query->get('minRating'),
            'search'       => $request->query->get('search'),
            'sortBy'       => $request->query->get('sortBy', 'newest'),
            'page'         => $request->query->getInt('page', 1),
            'limit'        => $request->query->getInt('limit', 20),
        ]);

        return $this->success([
            'products'   => array_map(
                fn($p) => $this->productService->formatCard($p),
                $result['products']
            ),
            'total'      => $result['total'],
            'page'       => $result['page'],
            'limit'      => $result['limit'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    // ── GET /api/products/{slug} ──────────────────────────────────────────
    /**
     * Fiche produit complète.
     * Appelé quand le client clique sur un produit depuis le catalogue.
     *
     * Retourne : infos complètes + toutes les variantes + toutes les images
     *
     * Ex : GET /api/products/chemise-homme-bleu-marine
     */
    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $product = $this->productRepository->findBySlug($slug);
        if (!$product) return $this->notFound('Produit introuvable.');

        return $this->success($this->productService->formatOne($product));
    }

    // ── GET /api/products/{slug}/similar ─────────────────────────────────
    /**
     * Produits similaires — même catégorie.
     * Affiché en bas de la fiche produit : "Vous aimerez aussi"
     */
    #[Route('/{slug}/similar', name: 'similar', methods: ['GET'])]
    public function similar(string $slug): JsonResponse
    {
        $product = $this->productRepository->findBySlug($slug);
        if (!$product) return $this->notFound('Produit introuvable.');

        $similar = $this->productRepository->findSimilar(
            $product->getId(),
            $product->getCategory()->getId(),
            6
        );

        return $this->success(
            array_map(fn($p) => $this->productService->formatCard($p), $similar)
        );
    }

    // ── GET /api/products/section/new-arrivals ────────────────────────────
    /**
     * Nouveaux arrivages — pour la section "Nouveaux Arrivages" de la page d'accueil.
     * ?limit=8
     */
    #[Route('/section/new-arrivals', name: 'new_arrivals', methods: ['GET'])]
    public function newArrivals(Request $request): JsonResponse
    {
        $limit    = min(20, $request->query->getInt('limit', 8));
        $products = $this->productRepository->findNewArrivals($limit);

        return $this->success(
            array_map(fn($p) => $this->productService->formatCard($p), $products)
        );
    }

    // ── GET /api/products/section/best-sellers ────────────────────────────
    /**
     * Meilleures ventes — pour la section "Meilleures Ventes" de la page d'accueil.
     */
    #[Route('/section/best-sellers', name: 'best_sellers', methods: ['GET'])]
    public function bestSellers(Request $request): JsonResponse
    {
        $limit    = min(20, $request->query->getInt('limit', 8));
        $products = $this->productRepository->findBestSellers($limit);

        return $this->success(
            array_map(fn($p) => $this->productService->formatCard($p), $products)
        );
    }

    // ── GET /api/products/section/on-sale ─────────────────────────────────
    /**
     * Produits en promotion — pour la page Soldes.
     */
    #[Route('/section/on-sale', name: 'on_sale', methods: ['GET'])]
    public function onSale(Request $request): JsonResponse
    {
        $limit    = min(20, $request->query->getInt('limit', 8));
        $products = $this->productRepository->findOnSale($limit);

        return $this->success(
            array_map(fn($p) => $this->productService->formatCard($p), $products)
        );
    }
}