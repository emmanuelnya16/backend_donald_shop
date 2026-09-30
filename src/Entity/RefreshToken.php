<?php

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_tokens')]
#[ORM\HasLifecycleCallbacks]
class RefreshToken
{
    // Durée de vie du refresh token : 7 jours
    public const TTL_DAYS = 7;

    // Type d'utilisateur associé au token
    public const TYPE_USER  = 'user';
    public const TYPE_ADMIN = 'admin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Le token lui-même — 64 caractères hex aléatoires
    // Stocké hashé en base (comme un mot de passe)
    // On ne stocke JAMAIS le token en clair en base
    #[ORM\Column(type: 'string', length: 128, unique: true)]
    private string $tokenHash;

    // Type : 'user' ou 'admin'
    #[ORM\Column(type: 'string', length: 10)]
    private string $userType;

    // ID de l'utilisateur concerné
    #[ORM\Column(type: 'integer')]
    private int $userId;

    // Date d'expiration — 7 jours après création
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    // Date de création
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    // IP de l'utilisateur au moment de la création
    // Utile pour détecter les usurpations
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $createdFromIp = null;

    // User-Agent du navigateur
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $userAgent = null;

    // true si ce token a déjà été utilisé pour un refresh
    // Un refresh token ne peut être utilisé qu'une seule fois
    // À chaque refresh on crée un nouveau token (rotation)
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isUsed = false;

    // true si révoqué manuellement (logout)
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isRevoked = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable(
            sprintf('+%d days', self::TTL_DAYS)
        );
    }

    // ── Helpers métier ─────────────────────────────────────────────────

    public function isValid(): bool
    {
        if ($this->isUsed)    return false;
        if ($this->isRevoked) return false;
        if ($this->isExpired()) return false;
        return true;
    }

    public function isExpired(): bool
    {
        return new \DateTimeImmutable() > $this->expiresAt;
    }

    public function markAsUsed(): static
    {
        $this->isUsed = true;
        return $this;
    }

    public function revoke(): static
    {
        $this->isRevoked = true;
        return $this;
    }

    // Génère un token aléatoire et retourne le token en clair
    // Le hash est stocké en base, le clair est envoyé au client
    public static function generate(): array
    {
        $plain = bin2hex(random_bytes(32)); // 64 caractères hex
        $hash  = hash('sha256', $plain);    // hash SHA256 du token

        return [
            'plain' => $plain,
            'hash'  => $hash,
        ];
    }

    // Vérifie si un token en clair correspond au hash stocké
    public function verify(string $plainToken): bool
    {
        return hash_equals($this->tokenHash, hash('sha256', $plainToken));
    }

    // ── Getters / Setters ──────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $v): static { $this->tokenHash = $v; return $this; }

    public function getUserType(): string { return $this->userType; }
    public function setUserType(string $v): static { $this->userType = $v; return $this; }

    public function getUserId(): int { return $this->userId; }
    public function setUserId(int $v): static { $this->userId = $v; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $v): static { $this->expiresAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getCreatedFromIp(): ?string { return $this->createdFromIp; }
    public function setCreatedFromIp(?string $v): static { $this->createdFromIp = $v; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $v): static { $this->userAgent = $v; return $this; }

    public function isUsed(): bool { return $this->isUsed; }
    public function isRevoked(): bool { return $this->isRevoked; }
}