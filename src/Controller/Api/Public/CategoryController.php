<?php

namespace App\Controller\Api\Public;

use App\Controller\Api\AbstractApiController;
use App\Repository\CategoryRepository;
use App\Service\Catalogue\CategoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/categories', name: 'api_public_categories_')]
class CategoryController extends AbstractApiController
{
    public function __construct(
        private readonly CategoryService    $categoryService,
        private readonly CategoryRepository $categoryRepository,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ── GET /api/categories ───────────────────────────────────────────────
    /**
     * Retourne toutes les catégories racines avec leurs enfants.
     * Utilisé pour :
     *  - Le menu principal du site (Homme, Femme, Chaussures, Électroménager)
     *  - La page d'entrée du catalogue (/catalogue)
     *  - Les filtres du catalogue
     *
     * Réponse :
     * {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1, "name": "Homme", "slug": "homme",
     *       "children": [
     *         { "id": 5, "name": "Chemises", "slug": "chemises" },
     *         { "id": 6, "name": "Pantalons", "slug": "pantalons" }
     *       ]
     *     },
     *     ...
     *   ]
     * }
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $categories = $this->categoryRepository->findRootsWithChildren();
        return $this->success(
            $this->categoryService->formatList($categories, withChildren: true)
        );
    }

    // ── GET /api/categories/{slug} ────────────────────────────────────────
    /**
     * Retourne une catégorie par son slug avec ses enfants.
     * Utilisé pour l'en-tête de la page catalogue d'une catégorie.
     *
     * Ex : GET /api/categories/homme
     */
    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        $category = $this->categoryRepository->findBySlug($slug);
        if (!$category) return $this->notFound('Catégorie introuvable.');
        if (!$category->isActive()) return $this->notFound('Catégorie introuvable.');

        return $this->success(
            $this->categoryService->formatOne($category, withChildren: true)
        );
    }
}