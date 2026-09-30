<?php

namespace App\Entity;

use App\Repository\ProductImageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductImageRepository::class)]
#[ORM\Table(name: 'product_images')]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // Nom du fichier stocké (Cloudinary public_id ou nom local)
    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    // URL complète retournée par Cloudinary
    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $url = null;

    // Ordre d'affichage dans la galerie
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $position = 0;

    // true = image principale affichée en priorité
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isMain = false;

    // Couleur associée — permet de changer la photo quand
    // l'utilisateur sélectionne une couleur sur la fiche produit
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $color = null;

    // Relation
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getFilename(): string { return $this->filename; }
    public function setFilename(string $v): static { $this->filename = $v; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): static { $this->url = $v; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $v): static { $this->position = $v; return $this; }

    public function isMain(): bool { return $this->isMain; }
    public function setIsMain(bool $v): static { $this->isMain = $v; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $v): static { $this->color = $v; return $this; }

    public function getProduct(): Product { return $this->product; }
    public function setProduct(Product $v): static { $this->product = $v; return $this; }
}