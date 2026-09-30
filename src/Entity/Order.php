<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    // ── Statuts ───────────────────────────────────────────────────────────
    public const STATUS_PENDING_PAYMENT = 'pending_payment';  // paiement mobile en cours (polling actif)
    public const STATUS_PAYMENT_FAILED  = 'payment_failed';   // échec paiement mobile
    public const STATUS_CONFIRMED       = 'confirmed';        // paiement confirmé OU COD accepté
    public const STATUS_PROCESSING      = 'processing';       // en préparation
    public const STATUS_SHIPPED         = 'shipped';          // remis au livreur
    public const STATUS_DELIVERED       = 'delivered';        // livré
    public const STATUS_CANCELLED       = 'cancelled';        // annulée
    public const STATUS_PENDING_COD     = 'pending_cod';      // paiement à la livraison en attente

    // ── Modes de paiement ─────────────────────────────────────────────────
    public const PAYMENT_MTN    = 'mtn_momo';
    public const PAYMENT_ORANGE = 'orange_money';
    public const PAYMENT_CASH   = 'cash_on_delivery';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Numéro lisible affiché au client : DG-2025-0042
    #[ORM\Column(type: 'string', length: 30, unique: true)]
    private string $orderNumber;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status = self::STATUS_PENDING_PAYMENT;

    #[ORM\Column(type: 'string', length: 20)]
    private string $paymentMethod;

    // Montants en FCFA (entiers)
    #[ORM\Column(type: 'integer')]
    private int $itemsTotal;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $deliveryFee = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $paymentFee = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $discountAmount = 0;

    #[ORM\Column(type: 'integer')]
    private int $totalAmount;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $promoCodeUsed = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNotes = null;

    // Nombre de tentatives de polling effectuées pour ce paiement
    // Permet de limiter le polling côté backend si besoin
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $paymentPollAttempts = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // ── Relations ─────────────────────────────────────────────────────────

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class,
        cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\OneToOne(mappedBy: 'order', targetEntity: DeliveryAddress::class,
        cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?DeliveryAddress $deliveryAddress = null;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: Payment::class,
        cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['initiatedAt' => 'DESC'])]
    private Collection $payments;

    public function __construct()
    {
        $this->items     = new ArrayCollection();
        $this->payments  = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    public function getLatestPayment(): ?Payment
    {
        return $this->payments->first() ?: null; // trié par initiatedAt DESC
    }

    public function getSuccessfulPayment(): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->isConfirmed()) return $payment;
        }
        return null;
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [
            self::STATUS_CONFIRMED,
            self::STATUS_PROCESSING,
            self::STATUS_SHIPPED,
            self::STATUS_DELIVERED,
        ]);
    }

    public function isCashOnDelivery(): bool
    {
        return $this->paymentMethod === self::PAYMENT_CASH;
    }

    public function isAwaitingMobilePayment(): bool
    {
        return $this->status === self::STATUS_PENDING_PAYMENT;
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_PAYMENT,
            self::STATUS_CONFIRMED,
            self::STATUS_PENDING_COD,
            self::STATUS_PROCESSING,  // l'admin peut annuler une commande en préparation
            self::STATUS_SHIPPED,     // l'admin peut annuler une commande expédiée (livreur n'a pas remis)
        ]);
    }

    public function incrementPollAttempts(): static
    {
        $this->paymentPollAttempts++;
        return $this;
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_PENDING_PAYMENT => 'En attente de paiement',
            self::STATUS_PAYMENT_FAILED  => 'Paiement échoué',
            self::STATUS_CONFIRMED       => 'Confirmée',
            self::STATUS_PROCESSING      => 'En préparation',
            self::STATUS_SHIPPED         => 'Expédiée',
            self::STATUS_DELIVERED       => 'Livrée',
            self::STATUS_CANCELLED       => 'Annulée',
            self::STATUS_PENDING_COD     => 'En attente paiement livraison',
            default                      => $this->status,
        };
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getOrderNumber(): string { return $this->orderNumber; }
    public function setOrderNumber(string $v): static { $this->orderNumber = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getPaymentMethod(): string { return $this->paymentMethod; }
    public function setPaymentMethod(string $v): static { $this->paymentMethod = $v; return $this; }

    public function getItemsTotal(): int { return $this->itemsTotal; }
    public function setItemsTotal(int $v): static { $this->itemsTotal = $v; return $this; }

    public function getDeliveryFee(): int { return $this->deliveryFee; }
    public function setDeliveryFee(int $v): static { $this->deliveryFee = $v; return $this; }

    public function getPaymentFee(): int { return $this->paymentFee; }
    public function setPaymentFee(int $v): static { $this->paymentFee = $v; return $this; }

    public function getDiscountAmount(): int { return $this->discountAmount; }
    public function setDiscountAmount(int $v): static { $this->discountAmount = $v; return $this; }

    public function getTotalAmount(): int { return $this->totalAmount; }
    public function setTotalAmount(int $v): static { $this->totalAmount = $v; return $this; }

    public function getPromoCodeUsed(): ?string { return $this->promoCodeUsed; }
    public function setPromoCodeUsed(?string $v): static { $this->promoCodeUsed = $v; return $this; }

    public function getAdminNotes(): ?string { return $this->adminNotes; }
    public function setAdminNotes(?string $v): static { $this->adminNotes = $v; return $this; }

    public function getPaymentPollAttempts(): int { return $this->paymentPollAttempts; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $v): static { $this->user = $v; return $this; }

    public function getItems(): Collection { return $this->items; }
    public function addItem(OrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }
        return $this;
    }

    public function getDeliveryAddress(): ?DeliveryAddress { return $this->deliveryAddress; }
    public function setDeliveryAddress(?DeliveryAddress $v): static
    {
        if ($v !== null) $v->setOrder($this);
        $this->deliveryAddress = $v;
        return $this;
    }

    public function getPayments(): Collection { return $this->payments; }
    public function addPayment(Payment $p): static
    {
        if (!$this->payments->contains($p)) {
            $this->payments->add($p);
            $p->setOrder($this);
        }
        return $this;
    }
}