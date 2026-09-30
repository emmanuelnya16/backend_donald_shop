<?php

namespace App\DTO\Catalogue;

use Symfony\Component\Validator\Constraints as Assert;

// ── DTO Variante ─────────────────────────────────────────────────────────
final class ProductVariantDTO
{
    public ?string $size       = null;
    public ?string $color      = null;
    public ?string $colorHex   = null;

    #[Assert\GreaterThanOrEqual(0)]
    public int $stock = 0;

    #[Assert\GreaterThanOrEqual(0)]
    public int $alertThreshold = 5;

    // Prix supplémentaire par rapport au prix de base
    #[Assert\GreaterThanOrEqual(0)]
    public int $extraPrice = 0;

    public ?string $sku      = null;
    public bool    $isActive = true;
}

// ── DTO Création / Modification de Produit ───────────────────────────────
final class ProductDTO
{
    #[Assert\NotBlank(message: 'Le nom du produit est obligatoire.')]
    #[Assert\Length(max: 200)]
    public string $name = '';

    // Slug optionnel — auto-généré depuis le nom si absent
    #[Assert\Length(max: 220)]
    public ?string $slug = null;

    #[Assert\Length(max: 400)]
    public ?string $shortDescription = null;

    public ?string $longDescription = null;

    #[Assert\NotBlank(message: 'Le prix est obligatoire.')]
    #[Assert\GreaterThan(0, message: 'Le prix doit être supérieur à 0 FCFA.')]
    public int $basePrice = 0;

    public ?int $promoPrice = null;

    public ?string $promoStartsAt = null; // format : 'Y-m-d H:i:s'
    public ?string $promoEndsAt   = null;

    #[Assert\NotBlank(message: 'La catégorie est obligatoire.')]
    public int $categoryId = 0;

    // active | draft | archived
    public string $status = 'draft';

    // SEO
    #[Assert\Length(max: 70)]
    public ?string $metaTitle = null;

    #[Assert\Length(max: 170)]
    public ?string $metaDescription = null;

    // Variantes — tableau de ProductVariantDTO
    // @var ProductVariantDTO[]
    public array $variants = [];
}