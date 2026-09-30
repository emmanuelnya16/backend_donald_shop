<?php

namespace App\Entity;

use App\Repository\PromoCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
#[ORM\Table(name: 'promo_codes')]
class PromoCode
{
    public const TYPE_PERCENT = 'percent'; // remise en pourcentage
    public const TYPE_FIXED   = 'fixed';   // remise en montant fixe FCFA

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Code saisi par le client — ex : DG2025, PROMO10
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9_-]+$/',
        message: 'Le code ne doit contenir que des majuscules, chiffres, tirets ou underscores.'
    )]
    private string $code;

    // Type de remise : percent | fixed
    #[ORM\Column(type: 'string', length: 10)]
    private string $type = self::TYPE_PERCENT;

    // Valeur : 15 pour 15% ou 5000 pour 5000 FCFA
    #[ORM\Column(type: 'integer')]
    #[Assert\GreaterThan(0)]
    private int $value;

    // Montant minimum de commande pour utiliser ce code (0 = pas de minimum)
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $minOrderAmount = 0;

    // Nombre max d'utilisations (null = illimité)
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxUses = null;

    // Nombre d'utilisations actuelles
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $usedCount = 0;

    // Limiter à 1 utilisation par client
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $oncePerUser = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    public function isValid(\DateTimeImmutable $now = null): bool
    {
        $now = $now ?? new \DateTimeImmutable();
        if (!$this->isActive) return false;
        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) return false;
        if ($this->startsAt && $now < $this->startsAt) return false;
        if ($this->endsAt   && $now > $this->endsAt)   return false;
        return true;
    }

    public function isExpired(): bool
    {
        if ($this->endsAt === null) return false;
        return new \DateTimeImmutable() > $this->endsAt;
    }

    public function hasReachedMaxUses(): bool
    {
        if ($this->maxUses === null) return false;
        return $this->usedCount >= $this->maxUses;
    }

    // Calcule la remise appliquée sur un montant donné (en FCFA)
    public function computeDiscount(int $orderAmount): int
    {
        if ($orderAmount < $this->minOrderAmount) return 0;

        if ($this->type === self::TYPE_PERCENT) {
            return (int) round($orderAmount * $this->value / 100);
        }

        // Remise fixe : on ne peut pas rembourser plus que le montant
        return min($this->value, $orderAmount);
    }

    public function incrementUsage(): static
    {
        $this->usedCount++;
        return $this;
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $v): static { $this->code = strtoupper(trim($v)); return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $v): static { $this->type = $v; return $this; }

    public function getValue(): int { return $this->value; }
    public function setValue(int $v): static { $this->value = $v; return $this; }

    public function getMinOrderAmount(): int { return $this->minOrderAmount; }
    public function setMinOrderAmount(int $v): static { $this->minOrderAmount = $v; return $this; }

    public function getMaxUses(): ?int { return $this->maxUses; }
    public function setMaxUses(?int $v): static { $this->maxUses = $v; return $this; }

    public function getUsedCount(): int { return $this->usedCount; }
    public function setUsedCount(int $v): static { $this->usedCount = $v; return $this; }

    public function isOncePerUser(): bool { return $this->oncePerUser; }
    public function setOncePerUser(bool $v): static { $this->oncePerUser = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $v): static { $this->startsAt = $v; return $this; }

    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $v): static { $this->endsAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}