<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
class Payment
{
    // ── Providers PawaPay (Cameroun) ─────────────────────────────────────
    // Correspond exactement aux valeurs attendues par l'API PawaPay v2
    public const PROVIDER_MTN_CMR    = 'MTN_MOMO_CMR'; // ✅ confirmé PawaPay sandbox
    public const PROVIDER_ORANGE_CMR = 'ORANGE_CMR';    // ✅ confirmé PawaPay sandbox (pas ORANGE_MONEY_CMR !)
    public const PROVIDER_CASH       = 'cash';

    // ── Statuts internes ──────────────────────────────────────────────────
    public const STATUS_INITIATED = 'initiated'; // dépôt créé, demande envoyée à PawaPay
    public const STATUS_SUBMITTED = 'submitted'; // envoyée à l'opérateur (MTN/Orange)
    public const STATUS_PROCESSING = 'processing'; // en cours de traitement
    public const STATUS_CONFIRMED  = 'confirmed';   // COMPLETED côté PawaPay
    public const STATUS_FAILED     = 'failed';      // FAILED / REJECTED / EXPIRED côté PawaPay
    public const STATUS_REFUNDED   = 'refunded';

    // Devise — toujours XAF pour le Cameroun
    public const CURRENCY = 'XAF';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // MTN_MOMO_CMR | ORANGE_MONEY_CMR | cash
    #[ORM\Column(type: 'string', length: 30)]
    private string $provider;

    // Montant exact en FCFA (toujours XAF)
    #[ORM\Column(type: 'integer')]
    private int $amount;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_INITIATED;

    // Numéro de téléphone utilisé pour le paiement — format 237XXXXXXXXX
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $payerPhone = null;

    // ── Référence PawaPay ──────────────────────────────────────────────────

    // L'identifiant unique généré par NOUS (UUID) et envoyé à PawaPay
    // C'est cet ID qui sert à la fois à créer le dépôt ET à vérifier son statut
    // GET /v2/deposits/{depositId}
    #[ORM\Column(type: 'string', length: 100, nullable: true, unique: true)]
    private ?string $depositId = null;

    // Réponse brute de PawaPay (JSON complet) — utile pour debug/audit
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $providerResponse = null;

    // Code et message d'échec retournés par PawaPay si FAILED
    // Ex: PAYER_NOT_FOUND, INSUFFICIENT_BALANCE, PAYMENT_NOT_APPROVED...
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $failureCode = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $failureMessage = null;

    // ── Suivi du polling ───────────────────────────────────────────────────

    // Nombre de fois où on a vérifié le statut via GET /deposits/{depositId}
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $statusCheckCount = 0;

    // Dernière fois qu'on a vérifié le statut auprès de PawaPay
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $initiatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    public function __construct()
    {
        $this->initiatedAt = new \DateTimeImmutable();
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    public function isConfirmed(): bool { return $this->status === self::STATUS_CONFIRMED; }
    public function isFailed(): bool    { return $this->status === self::STATUS_FAILED; }
    public function isPending(): bool
    {
        return in_array($this->status, [
            self::STATUS_INITIATED,
            self::STATUS_SUBMITTED,
            self::STATUS_PROCESSING,
        ]);
    }
    public function isCash(): bool   { return $this->provider === self::PROVIDER_CASH; }
    public function isMtn(): bool    { return $this->provider === self::PROVIDER_MTN_CMR; }
    public function isOrange(): bool { return $this->provider === self::PROVIDER_ORANGE_CMR; }

    public function markAsConfirmed(): static
    {
        $this->status      = self::STATUS_CONFIRMED;
        $this->confirmedAt = new \DateTimeImmutable();
        return $this;
    }

    public function markAsFailed(?string $code = null, ?string $message = null): static
    {
        $this->status         = self::STATUS_FAILED;
        $this->failedAt       = new \DateTimeImmutable();
        $this->failureCode    = $code;
        $this->failureMessage = $message;
        return $this;
    }

    // Met à jour le statut interne depuis le statut brut PawaPay
    public function updateFromPawapayStatus(string $pawapayStatus): static
    {
        $this->status = match ($pawapayStatus) {
            'ACCEPTED'   => self::STATUS_INITIATED,
            'SUBMITTED'  => self::STATUS_SUBMITTED,
            'PROCESSING' => self::STATUS_PROCESSING,
            'COMPLETED'  => self::STATUS_CONFIRMED,
            'FAILED', 'REJECTED', 'EXPIRED' => self::STATUS_FAILED,
            default      => $this->status,
        };

        if ($this->status === self::STATUS_CONFIRMED) {
            $this->confirmedAt = new \DateTimeImmutable();
        }
        if ($this->status === self::STATUS_FAILED) {
            $this->failedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function incrementStatusCheck(): static
    {
        $this->statusCheckCount++;
        $this->lastCheckedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getProviderLabel(): string
    {
        return match($this->provider) {
            self::PROVIDER_MTN_CMR    => 'MTN Mobile Money',
            self::PROVIDER_ORANGE_CMR => 'Orange Money',
            self::PROVIDER_CASH       => 'Paiement à la livraison',
            default                   => $this->provider,
        };
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $v): static { $this->provider = $v; return $this; }

    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $v): static { $this->amount = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getPayerPhone(): ?string { return $this->payerPhone; }
    public function setPayerPhone(?string $v): static { $this->payerPhone = $v; return $this; }

    public function getDepositId(): ?string { return $this->depositId; }
    public function setDepositId(?string $v): static { $this->depositId = $v; return $this; }

    public function getProviderResponse(): ?array { return $this->providerResponse; }
    public function setProviderResponse(?array $v): static { $this->providerResponse = $v; return $this; }

    public function getFailureCode(): ?string { return $this->failureCode; }
    public function setFailureCode(?string $v): static { $this->failureCode = $v; return $this; }

    public function getFailureMessage(): ?string { return $this->failureMessage; }
    public function setFailureMessage(?string $v): static { $this->failureMessage = $v; return $this; }

    public function getStatusCheckCount(): int { return $this->statusCheckCount; }
    public function getLastCheckedAt(): ?\DateTimeImmutable { return $this->lastCheckedAt; }

    public function getInitiatedAt(): \DateTimeImmutable { return $this->initiatedAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getFailedAt(): ?\DateTimeImmutable { return $this->failedAt; }

    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $v): static { $this->order = $v; return $this; }
}