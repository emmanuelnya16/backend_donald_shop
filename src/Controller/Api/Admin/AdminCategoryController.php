<?php

namespace App\Controller\Api\Admin;

use App\Controller\Api\AbstractApiController;
use App\DTO\Catalogue\CategoryDTO;
use App\Repository\CategoryRepository;
use App\Service\Catalogue\CategoryService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/categories', name: 'api_admin_categories_')]
#[IsGranted('ROLE_ADMIN')]
class AdminCategoryController extends AbstractApiController
{
    public function __construct(
        private readonly CategoryService    $categoryService,
        private readonly CategoryRepository $categoryRepository,
        \Symfony\Component\Validator\Validator\ValidatorInterface $validator,
        \Symfony\Component\Serializer\SerializerInterface         $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // GET /api/admin/categories
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $categories = $this->categoryRepository->findAllForAdmin();
        return $this->success(
            $this->categoryService->formatList($categories, withChildren: true)
        );
    }

    // GET /api/admin/categories/{id}
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) return $this->notFound('Catégorie introuvable.');
        return $this->success($this->categoryService->formatOne($category, withChildren: true));
    }

    // POST /api/admin/categories
    // Body : { "name": "Homme", "nameEn": "Men", "parentId": null, "position": 0 }
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, CategoryDTO::class);
        if (!$dto) return $this->error('JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        try {
            $category = $this->categoryService->create($dto);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->created($this->categoryService->formatOne($category), 'Catégorie créée.');
    }

    // PUT /api/admin/categories/{id}
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) return $this->notFound('Catégorie introuvable.');

        $dto = $this->deserialize($request, CategoryDTO::class);
        if (!$dto) return $this->error('JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        try {
            $category = $this->categoryService->update($category, $dto);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->success($this->categoryService->formatOne($category), 'Catégorie mise à jour.');
    }

    // DELETE /api/admin/categories/{id}
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $category = $this->categoryRepository->find($id);
        if (!$category) return $this->notFound('Catégorie introuvable.');

        try {
            $this->categoryService->delete($category);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->success(null, 'Catégorie supprimée.');
    }
}