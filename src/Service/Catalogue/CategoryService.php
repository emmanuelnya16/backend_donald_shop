<?php

namespace App\Service\Catalogue;

use App\DTO\Catalogue\CategoryDTO;
use App\Entity\Category;
use App\Repository\CategoryRepository;

class CategoryService
{
    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly SlugService        $slugService,
    ) {}

    // ── Création ─────────────────────────────────────────────────────────

    public function create(CategoryDTO $dto): Category
    {
        $category = new Category();
        $this->hydrate($category, $dto);
        $this->categoryRepository->save($category, flush: true);
        return $category;
    }

    // ── Modification ──────────────────────────────────────────────────────

    public function update(Category $category, CategoryDTO $dto): Category
    {
        $this->hydrate($category, $dto, $category->getId());
        $this->categoryRepository->save($category, flush: true);
        return $category;
    }

    // ── Suppression ──────────────────────────────────────────────────────

    /**
     * @throws \DomainException si la catégorie contient des produits ou des enfants
     */
    public function delete(Category $category): void
    {
        if ($category->hasChildren()) {
            throw new \DomainException(
                'Impossible de supprimer une catégorie qui contient des sous-catégories.'
            );
        }

        if ($category->getProductCount() > 0) {
            throw new \DomainException(
                sprintf(
                    'Impossible de supprimer la catégorie "%s" : elle contient %d produit(s).',
                    $category->getName(),
                    $category->getProductCount()
                )
            );
        }

        $this->categoryRepository->remove($category, flush: true);
    }

    // ── Formatage pour les réponses JSON ─────────────────────────────────

    public function formatOne(Category $category, bool $withChildren = false): array
    {
        $data = [
            'id'       => $category->getId(),
            'name'     => $category->getName(),
            'nameEn'   => $category->getNameEn(),
            'slug'     => $category->getSlug(),
            'image'    => $category->getImage(),
            'position' => $category->getPosition(),
            'isActive' => $category->isActive(),
            'parent'   => $category->getParent() ? [
                'id'   => $category->getParent()->getId(),
                'name' => $category->getParent()->getName(),
                'slug' => $category->getParent()->getSlug(),
            ] : null,
            'productCount' => $category->isRoot()
                ? $this->categoryRepository->countProductsRecursive($category->getId())
                : $category->getProductCount(),
        ];

        if ($withChildren) {
            $data['children'] = array_map(
                fn(Category $child) => $this->formatOne($child),
                $category->getChildren()->toArray()
            );
        }

        return $data;
    }

    public function formatList(array $categories, bool $withChildren = false): array
    {
        return array_map(
            fn(Category $cat) => $this->formatOne($cat, $withChildren),
            $categories
        );
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    private function hydrate(Category $category, CategoryDTO $dto, ?int $excludeId = null): void
    {
        $category->setName(trim($dto->name));
        $category->setNameEn($dto->nameEn ? trim($dto->nameEn) : null);
        $category->setPosition($dto->position);
        $category->setIsActive($dto->isActive);

        // Slug : utilise celui fourni ou génère automatiquement
        $slug = $dto->slug
            ? $this->slugService->slugify($dto->slug)
            : $this->slugService->generateForCategory($dto->name, $excludeId);

        $category->setSlug($slug);

        // Catégorie parente
        if ($dto->parentId) {
            $parent = $this->categoryRepository->find($dto->parentId);
            if (!$parent) {
                throw new \DomainException('Catégorie parente introuvable.');
            }
            // Empêche une catégorie d'être son propre parent
            if ($excludeId && $parent->getId() === $excludeId) {
                throw new \DomainException('Une catégorie ne peut pas être son propre parent.');
            }
            $category->setParent($parent);
        } else {
            $category->setParent(null);
        }
    }
}