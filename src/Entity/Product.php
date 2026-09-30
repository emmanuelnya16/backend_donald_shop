<?php
namespace App\Entity;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
#[ORM\HasLifecycleCallbacks]
class Product
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 200)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'string', length: 220, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 400, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $longDescription = null;

    // Prix en FCFA — entier pour éviter les float
    #[ORM\Column(type: 'integer')]
    #[Assert\GreaterThan(0)]
    private int $basePrice;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $promoPrice = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $promoStartsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $promoEndsAt = null;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => 'draft'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: 'string', length: 70, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(type: 'string', length: 170, nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $salesCount = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $averageRating = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $reviewCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class,
        cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $variants;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductImage::class,
        cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $images;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: Review::class)]
    private Collection $reviews;

    public function __construct()
    {
        $this->variants  = new ArrayCollection();
        $this->images    = new ArrayCollection();
        $this->reviews   = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isOnSale(): bool
    {
        if ($this->promoPrice === null) return false;
        $now = new \DateTimeImmutable();
        if ($this->promoStartsAt && $now < $this->promoStartsAt) return false;
        if ($this->promoEndsAt   && $now > $this->promoEndsAt)   return false;
        return true;
    }

    public function getCurrentPrice(): int
    {
        return $this->isOnSale() ? $this->promoPrice : $this->basePrice;
    }

    public function getDiscountPercent(): ?int
    {
        if (!$this->isOnSale()) return null;
        return (int) round((1 - $this->promoPrice / $this->basePrice) * 100);
    }

    public function isActive(): bool   { return $this->status === self::STATUS_ACTIVE; }
    public function isDraft(): bool    { return $this->status === self::STATUS_DRAFT; }
    public function isArchived(): bool { return $this->status === self::STATUS_ARCHIVED; }

    public function getMainImage(): ?ProductImage
    {
        foreach ($this->images as $img) {
            if ($img->isMain()) return $img;
        }
        return $this->images->first() ?: null;
    }

    public function getTotalStock(): int
    {
        $total = 0;
        foreach ($this->variants as $v) { $total += $v->getStock(); }
        return $total;
    }

    public function isInStock(): bool { return $this->getTotalStock() > 0; }
    public function incrementSalesCount(int $qty = 1): static { $this->salesCount += $qty; return $this; }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $v): static { $this->slug = $v; return $this; }
    public function getShortDescription(): ?string { return $this->shortDescription; }
    public function setShortDescription(?string $v): static { $this->shortDescription = $v; return $this; }
    public function getLongDescription(): ?string { return $this->longDescription; }
    public function setLongDescription(?string $v): static { $this->longDescription = $v; return $this; }
    public function getBasePrice(): int { return $this->basePrice; }
    public function setBasePrice(int $v): static { $this->basePrice = $v; return $this; }
    public function getPromoPrice(): ?int { return $this->promoPrice; }
    public function setPromoPrice(?int $v): static { $this->promoPrice = $v; return $this; }
    public function getPromoStartsAt(): ?\DateTimeImmutable { return $this->promoStartsAt; }
    public function setPromoStartsAt(?\DateTimeImmutable $v): static { $this->promoStartsAt = $v; return $this; }
    public function getPromoEndsAt(): ?\DateTimeImmutable { return $this->promoEndsAt; }
    public function setPromoEndsAt(?\DateTimeImmutable $v): static { $this->promoEndsAt = $v; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): static { $this->status = $v; return $this; }
    public function getMetaTitle(): ?string { return $this->metaTitle; }
    public function setMetaTitle(?string $v): static { $this->metaTitle = $v; return $this; }
    public function getMetaDescription(): ?string { return $this->metaDescription; }
    public function setMetaDescription(?string $v): static { $this->metaDescription = $v; return $this; }
    public function getSalesCount(): int { return $this->salesCount; }
    public function setSalesCount(int $v): static { $this->salesCount = $v; return $this; }
    public function getAverageRating(): ?float { return $this->averageRating; }
    public function setAverageRating(?float $v): static { $this->averageRating = $v; return $this; }
    public function getReviewCount(): int { return $this->reviewCount; }
    public function setReviewCount(int $v): static { $this->reviewCount = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getCategory(): Category { return $this->category; }
    public function setCategory(Category $v): static { $this->category = $v; return $this; }
    public function getVariants(): Collection { return $this->variants; }
    public function addVariant(ProductVariant $v): static
    {
        if (!$this->variants->contains($v)) { $this->variants->add($v); $v->setProduct($this); }
        return $this;
    }
    public function removeVariant(ProductVariant $v): static { $this->variants->removeElement($v); return $this; }
    public function getImages(): Collection { return $this->images; }
    public function addImage(ProductImage $img): static
    {
        if (!$this->images->contains($img)) { $this->images->add($img); $img->setProduct($this); }
        return $this;
    }
    public function removeImage(ProductImage $img): static { $this->images->removeElement($img); return $this; }
    public function getReviews(): Collection { return $this->reviews; }
}