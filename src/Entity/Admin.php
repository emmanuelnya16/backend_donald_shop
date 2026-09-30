<?php

namespace App\Entity;

use App\Repository\AdminRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AdminRepository::class)]
#[ORM\Table(name: 'admins')]
#[ORM\HasLifecycleCallbacks]
class Admin implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const ROLE_MANAGER     = 'ROLE_MANAGER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $firstName;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $lastName;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column(type: 'string', length: 50)]
    private string $role = self::ROLE_MANAGER;

    // ⚠️  false par défaut — un admin doit être explicitement activé
    // Seul le Super Admin créé via la commande CLI a isActive = true
    // Les admins créés depuis le back-office ont isActive = false
    // jusqu'à ce qu'ils activent leur compte via le lien email
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isActive = false;

    // true = doit changer son mot de passe à la première connexion
    // Tous les admins créés par le Super Admin ont ce flag à true
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $mustChangePassword = true;

    // Token d'activation envoyé par email lors de la création du compte
    // null = compte déjà activé
    #[ORM\Column(type: 'string', length: 100, nullable: true, unique: true)]
    private ?string $activationToken = null;

    // Date d'expiration du token d'activation (24h)
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activationTokenExpiresAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Sécurité — UserInterface ──────────────────────────────────────────

    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array { return [$this->role, 'ROLE_ADMIN']; }
    public function eraseCredentials(): void {}

    // ── Helpers métier ────────────────────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    // Génère un token d'activation unique valable 24h
    public function generateActivationToken(): static
    {
        $this->activationToken          = bin2hex(random_bytes(32));
        $this->activationTokenExpiresAt = new \DateTimeImmutable('+24 hours');
        return $this;
    }

    // Valide et consomme le token d'activation
    public function activate(string $token): bool
    {
        if ($this->activationToken === null) return false;
        if ($this->activationToken !== $token) return false;
        if ($this->activationTokenExpiresAt < new \DateTimeImmutable()) return false;

        $this->isActive               = true;
        $this->activationToken        = null;
        $this->activationTokenExpiresAt = null;

        return true;
    }

    public function isActivationTokenExpired(): bool
    {
        if ($this->activationTokenExpiresAt === null) return true;
        return new \DateTimeImmutable() > $this->activationTokenExpiresAt;
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): static { $this->firstName = $v; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): static { $this->lastName = $v; return $this; }

    public function getFullName(): string { return $this->firstName.' '.$this->lastName; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): static { $this->email = $v; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $v): static { $this->password = $v; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $v): static { $this->role = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function mustChangePassword(): bool { return $this->mustChangePassword; }
    public function setMustChangePassword(bool $v): static { $this->mustChangePassword = $v; return $this; }

    public function getActivationToken(): ?string { return $this->activationToken; }
    public function getActivationTokenExpiresAt(): ?\DateTimeImmutable { return $this->activationTokenExpiresAt; }

    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(?\DateTimeImmutable $v): static { $this->lastLoginAt = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}