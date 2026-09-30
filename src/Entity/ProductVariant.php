<?php

namespace App\Entity;

use App\Repository\ProductVariantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Table(name: 'product_variants')]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Taille : XS, S, M, L, XL, XXL ou pointure 38, 39... ou null si pas de taille
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $size = null;

    // Couleur : Bleu, Rouge, Noir... ou null si pas de couleur
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $color = null;

    // Code couleur hex pour affichage de la pastille (#1A56DB)
    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $colorHex = null;

    // Stock disponible pour cette variante précise
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\GreaterThanOrEqual(0)]
    private int $stock = 0;

    // Seuil d'alerte — quand stock <= alertThreshold, alerte admin
    #[ORM\Column(type: 'integer', options: ['default' => 5])]
    private int $alertThreshold = 5;

    // Prix supplémentaire par rapport au prix de base du produit
    // Ex : si XL coûte 2000 FCFA de plus → extraPrice = 2000
    // La plupart du temps = 0
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $extraPrice = 0;

    // Référence unique de la variante pour la gestion des stocks
    #[ORM\Column(type: 'string', length: 100, nullable: true, unique: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    // Relation
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\OneToMany(mappedBy: 'productVariant', targetEntity: OrderItem::class)]
    private Collection $orderItems;

    public function __construct()
    {
        $this->orderItems = new ArrayCollection();
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    public function getEffectivePrice(): int
    {
        return $this->product->getCurrentPrice() + $this->extraPrice;
    }

    public function isInStock(): bool { return $this->stock > 0; }

    public function isLowStock(): bool
    {
        return $this->stock > 0 && $this->stock <= $this->alertThreshold;
    }

    public function isOutOfStock(): bool { return $this->stock === 0; }

    public function decreaseStock(int $qty): static
    {
        if ($this->stock < $qty) {
            throw new \DomainException("Stock insuffisant pour la variante #{$this->id}");
        }
        $this->stock -= $qty;
        return $this;
    }

    public function increaseStock(int $qty): static
    {
        $this->stock += $qty;
        return $this;
    }

    // Label lisible : "Taille M — Bleu" ou "Taille L" ou "Bleu" ou "Standard"
    public function getLabel(): string
    {
        $parts = [];
        if ($this->size)  $parts[] = 'Taille '.$this->size;
        if ($this->color) $parts[] = $this->color;
        return implode(' — ', $parts) ?: 'Standard';
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getSize(): ?string { return $this->size; }
    public function setSize(?string $v): static { $this->size = $v; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $v): static { $this->color = $v; return $this; }

    public function getColorHex(): ?string { return $this->colorHex; }
    public function setColorHex(?string $v): static { $this->colorHex = $v; return $this; }

    public function getStock(): int { return $this->stock; }
    public function setStock(int $v): static { $this->stock = $v; return $this; }

    public function getAlertThreshold(): int { return $this->alertThreshold; }
    public function setAlertThreshold(int $v): static { $this->alertThreshold = $v; return $this; }

    public function getExtraPrice(): int { return $this->extraPrice; }
    public function setExtraPrice(int $v): static { $this->extraPrice = $v; return $this; }

    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $v): static { $this->sku = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $v): static { $this->product = $v; return $this; }

    public function getOrderItems(): Collection { return $this->orderItems; }
}