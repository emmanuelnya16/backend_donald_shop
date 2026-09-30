<?php

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Snapshot — important : la commande garde le nom même si le produit change après
    #[ORM\Column(type: 'string', length: 200)]
    private string $productName;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $variantLabel = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $productImageUrl = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\GreaterThan(0)]
    private int $quantity;

    // Prix unitaire au moment de la commande (snapshot)
    #[ORM\Column(type: 'integer')]
    private int $unitPrice;

    #[ORM\Column(type: 'integer')]
    private int $subtotal;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'orderItems')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductVariant $productVariant = null;

    // ── Helpers ───────────────────────────────────────────────────────────

    public function computeSubtotal(): static
    {
        $this->subtotal = $this->unitPrice * $this->quantity;
        return $this;
    }

    public static function fromVariant(ProductVariant $variant, int $quantity): self
    {
        $item = new self();
        $item->productVariant  = $variant;
        $item->productName     = $variant->getProduct()->getName();
        $item->variantLabel    = $variant->getLabel();
        $item->productImageUrl = $variant->getProduct()->getMainImage()?->getUrl();
        $item->quantity        = $quantity;
        $item->unitPrice       = $variant->getEffectivePrice();
        $item->subtotal        = $item->unitPrice * $quantity;
        return $item;
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getProductName(): string { return $this->productName; }
    public function setProductName(string $v): static { $this->productName = $v; return $this; }

    public function getVariantLabel(): ?string { return $this->variantLabel; }
    public function setVariantLabel(?string $v): static { $this->variantLabel = $v; return $this; }

    public function getProductImageUrl(): ?string { return $this->productImageUrl; }
    public function setProductImageUrl(?string $v): static { $this->productImageUrl = $v; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $v): static { $this->quantity = $v; return $this; }

    public function getUnitPrice(): int { return $this->unitPrice; }
    public function setUnitPrice(int $v): static { $this->unitPrice = $v; return $this; }

    public function getSubtotal(): int { return $this->subtotal; }
    public function setSubtotal(int $v): static { $this->subtotal = $v; return $this; }

    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $v): static { $this->order = $v; return $this; }

    public function getProductVariant(): ?ProductVariant { return $this->productVariant; }
    public function setProductVariant(?ProductVariant $v): static { $this->productVariant = $v; return $this; }
}