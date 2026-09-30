<?php

namespace App\Entity;

use App\Repository\ReviewRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Table(name: 'reviews')]
#[ORM\HasLifecycleCallbacks]
class Review
{
    public const STATUS_PENDING   = 'pending';    // en attente de modération
    public const STATUS_PUBLISHED = 'published';  // publié sur le site
    public const STATUS_REJECTED  = 'rejected';   // rejeté par l'admin

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\Range(min: 1, max: 5)]
    private int $rating;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    #[Assert\Length(max: 150)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 2000)]
    private string $body;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    // Raison du rejet — visible uniquement en admin
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $rejectionReason = null;

    // Variante achetée par ce client — snapshot pour affichage
    // Ex : "Taille M — Bleu Marine"
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $purchasedVariant = null;

    // Votes "utile" par d'autres clients
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $helpfulVotes = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $moderatedAt = null;

    // Relations
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'reviews')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Helpers métier ────────────────────────────────────────────────────

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isPublished(): bool { return $this->status === self::STATUS_PUBLISHED; }
    public function isRejected(): bool  { return $this->status === self::STATUS_REJECTED; }

    public function publish(): static
    {
        $this->status      = self::STATUS_PUBLISHED;
        $this->moderatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function reject(string $reason = ''): static
    {
        $this->status          = self::STATUS_REJECTED;
        $this->moderatedAt     = new \DateTimeImmutable();
        $this->rejectionReason = $reason;
        return $this;
    }

    public function incrementHelpfulVotes(): static
    {
        $this->helpfulVotes++;
        return $this;
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getRating(): int { return $this->rating; }
    public function setRating(int $v): static { $this->rating = $v; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = $v; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $v): static { $this->body = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }

    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejectionReason(?string $v): static { $this->rejectionReason = $v; return $this; }

    public function getPurchasedVariant(): ?string { return $this->purchasedVariant; }
    public function setPurchasedVariant(?string $v): static { $this->purchasedVariant = $v; return $this; }

    public function getHelpfulVotes(): int { return $this->helpfulVotes; }
    public function setHelpfulVotes(int $v): static { $this->helpfulVotes = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getModeratedAt(): ?\DateTimeImmutable { return $this->moderatedAt; }

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $v): static { $this->product = $v; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $v): static { $this->user = $v; return $this; }
}