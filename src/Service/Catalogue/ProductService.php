<?php

namespace App\Service\Catalogue;

use App\DTO\Catalogue\ProductDTO;
use App\DTO\Catalogue\ProductVariantDTO;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;

class ProductService
{
    public function __construct(
        private readonly ProductRepository        $productRepository,
        private readonly CategoryRepository       $categoryRepository,
        private readonly ProductVariantRepository $variantRepository,
        private readonly SlugService              $slugService,
    ) {}

    // ── Création ─────────────────────────────────────────────────────────

    public function create(ProductDTO $dto): Product
    {
        $product = new Product();
        $this->hydrateProduct($product, $dto);

        // Variantes
        foreach ($dto->variants as $variantData) {
            $variantDTO = $this->mapToVariantDTO($variantData);
            $variant    = $this->createVariant($variantDTO);
            $product->addVariant($variant);
        }

        $this->productRepository->save($product, flush: true);
        return $product;
    }

    // ── Modification ──────────────────────────────────────────────────────

    public function update(Product $product, ProductDTO $dto): Product
    {
        $this->hydrateProduct($product, $dto, $product->getId());

        // Supprime les variantes existantes et recrée
        // Approche simple — pour une app plus complexe on ferait un diff
        foreach ($product->getVariants() as $v) {
            $product->removeVariant($v);
        }

        foreach ($dto->variants as $variantData) {
            $variantDTO = $this->mapToVariantDTO($variantData);
            $variant    = $this->createVariant($variantDTO);
            $product->addVariant($variant);
        }

        $this->productRepository->save($product, flush: true);
        return $product;
    }

    // ── Archivage ────────────────────────────────────────────────────────

    public function archive(Product $product): Product
    {
        $product->setStatus(Product::STATUS_ARCHIVED);
        $this->productRepository->save($product, flush: true);
        return $product;
    }

    public function publish(Product $product): Product
    {
        $product->setStatus(Product::STATUS_ACTIVE);
        $this->productRepository->save($product, flush: true);
        return $product;
    }

    // ── Formatage pour les réponses JSON ─────────────────────────────────

    public function formatOne(Product $product): array
    {
        $mainImage = $product->getMainImage();

        return [
            'id'               => $product->getId(),
            'name'             => $product->getName(),
            'slug'             => $product->getSlug(),
            'shortDescription' => $product->getShortDescription(),
            'longDescription'  => $product->getLongDescription(),
            'basePrice'        => $product->getBasePrice(),
            'promoPrice'       => $product->getPromoPrice(),
            'currentPrice'     => $product->getCurrentPrice(),
            'isOnSale'         => $product->isOnSale(),
            'discountPercent'  => $product->getDiscountPercent(),
            'promoStartsAt'    => $product->getPromoStartsAt()?->format('Y-m-d H:i:s'),
            'promoEndsAt'      => $product->getPromoEndsAt()?->format('Y-m-d H:i:s'),
            'status'           => $product->getStatus(),
            'isInStock'        => $product->isInStock(),
            'totalStock'       => $product->getTotalStock(),
            'salesCount'       => $product->getSalesCount(),
            'averageRating'    => $product->getAverageRating(),
            'reviewCount'      => $product->getReviewCount(),
            'metaTitle'        => $product->getMetaTitle(),
            'metaDescription'  => $product->getMetaDescription(),
            'createdAt'        => $product->getCreatedAt()->format('Y-m-d'),
            'category'         => [
                'id'   => $product->getCategory()->getId(),
                'name' => $product->getCategory()->getName(),
                'slug' => $product->getCategory()->getSlug(),
            ],
            'mainImage'  => $mainImage ? [
                'id'  => $mainImage->getId(),
                'url' => $mainImage->getUrl(),
            ] : null,
            'images'   => array_map(
                fn($img) => [
                    'id'       => $img->getId(),
                    'url'      => $img->getUrl(),
                    'position' => $img->getPosition(),
                    'isMain'   => $img->isMain(),
                    'color'    => $img->getColor(),
                ],
                $product->getImages()->toArray()
            ),
            'variants' => array_map(
                fn(ProductVariant $v) => $this->formatVariant($v),
                $product->getVariants()->toArray()
            ),
        ];
    }

    // Format court pour les listes (catalogue, cartes produits)
    public function formatCard(Product $product): array
    {
        $mainImage = $product->getMainImage();

        return [
            'id'              => $product->getId(),
            'name'            => $product->getName(),
            'slug'            => $product->getSlug(),
            'shortDescription'=> $product->getShortDescription(),
            'basePrice'       => $product->getBasePrice(),
            'currentPrice'    => $product->getCurrentPrice(),
            'isOnSale'        => $product->isOnSale(),
            'discountPercent' => $product->getDiscountPercent(),
            'isInStock'       => $product->isInStock(),
            'averageRating'   => $product->getAverageRating(),
            'reviewCount'     => $product->getReviewCount(),
            'mainImage'       => $mainImage ? $mainImage->getUrl() : null,
            'availableSizes'  => $this->getAvailableSizes($product),
            'availableColors' => $this->getAvailableColors($product),
            'category'        => [
                'id'   => $product->getCategory()->getId(),
                'name' => $product->getCategory()->getName(),
                'slug' => $product->getCategory()->getSlug(),
            ],
            // ← Requis par le checkout pour résoudre le variantId
            'variants'        => array_map(
                fn(ProductVariant $v) => $this->formatVariant($v),
                $product->getVariants()->toArray()
            ),
        ];
    }

    public function formatVariant(ProductVariant $variant): array
    {
        return [
            'id'             => $variant->getId(),
            'size'           => $variant->getSize(),
            'color'          => $variant->getColor(),
            'colorHex'       => $variant->getColorHex(),
            'stock'          => $variant->getStock(),
            'alertThreshold' => $variant->getAlertThreshold(),
            'extraPrice'     => $variant->getExtraPrice(),
            'effectivePrice' => $variant->getEffectivePrice(),
            'sku'            => $variant->getSku(),
            'isActive'       => $variant->isActive(),
            'isInStock'      => $variant->isInStock(),
            'isLowStock'     => $variant->isLowStock(),
            'label'          => $variant->getLabel(),
        ];
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    private function hydrateProduct(Product $product, ProductDTO $dto, ?int $excludeId = null): void
    {
        // Catégorie
        $category = $this->categoryRepository->find($dto->categoryId);
        if (!$category) {
            throw new \DomainException('Catégorie introuvable.');
        }

        $product->setName(trim($dto->name));
        $product->setCategory($category);
        $product->setShortDescription($dto->shortDescription);
        $product->setLongDescription($dto->longDescription);
        $product->setBasePrice($dto->basePrice);
        $product->setPromoPrice($dto->promoPrice);
        $product->setStatus($dto->status);
        $product->setMetaTitle($dto->metaTitle);
        $product->setMetaDescription($dto->metaDescription);

        // Slug
        $slug = $dto->slug
            ? $this->slugService->slugify($dto->slug)
            : $this->slugService->generateForProduct($dto->name, $excludeId);
        $product->setSlug($slug);

        // Dates de promo
        if ($dto->promoStartsAt) {
            $product->setPromoStartsAt(new \DateTimeImmutable($dto->promoStartsAt));
        }
        if ($dto->promoEndsAt) {
            $product->setPromoEndsAt(new \DateTimeImmutable($dto->promoEndsAt));
        }
    }

    private function createVariant(ProductVariantDTO $dto): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->setSize($dto->size);
        $variant->setColor($dto->color);
        $variant->setColorHex($dto->colorHex);
        $variant->setStock($dto->stock);
        $variant->setAlertThreshold($dto->alertThreshold);
        $variant->setExtraPrice($dto->extraPrice);
        $variant->setSku($dto->sku);
        $variant->setIsActive($dto->isActive);
        return $variant;
    }

    private function mapToVariantDTO(mixed $data): ProductVariantDTO
    {
        $dto = new ProductVariantDTO();
        if (is_array($data)) {
            $dto->size           = $data['size']           ?? null;
            $dto->color          = $data['color']          ?? null;
            $dto->colorHex       = $data['colorHex']       ?? null;
            $dto->stock          = (int)  ($data['stock']          ?? 0);
            $dto->alertThreshold = (int)  ($data['alertThreshold'] ?? 5);
            $dto->extraPrice     = (int)  ($data['extraPrice']     ?? 0);
            $dto->sku            = $data['sku']            ?? null;
            $dto->isActive       = (bool) ($data['isActive']       ?? true);
        }
        return $dto;
    }

    private function getAvailableSizes(Product $product): array
    {
        $sizes = [];
        foreach ($product->getVariants() as $v) {
            if ($v->getSize() && $v->isActive()) {
                $sizes[] = $v->getSize();
            }
        }
        return array_unique($sizes);
    }

    private function getAvailableColors(Product $product): array
    {
        $colors = [];
        foreach ($product->getVariants() as $v) {
            if ($v->getColor() && $v->isActive()) {
                $colors[$v->getColor()] = [
                    'name' => $v->getColor(),
                    'hex'  => $v->getColorHex(),
                ];
            }
        }
        return array_values($colors);
    }
}