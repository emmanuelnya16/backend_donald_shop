<?php

namespace App\Entity;

use App\Repository\PageVisitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * PageVisit — Donald Gros E-commerce
 *
 * Enregistre chaque visite de page sur donaldgrosonline.com.
 * Données collectées sans GeoLite2 (city = null pour l'instant).
 *
 * Champs :
 *   id         → identifiant auto-incrémenté
 *   visitedAt  → date/heure de la visite
 *   page       → chemin de la page visitée  (ex: /products/chaussure-xyz)
 *   ip         → adresse IP du visiteur (récupérée par Symfony)
 *   referrer   → URL de provenance (ex: https://facebook.com, vide si accès direct)
 *   userAgent  → navigateur/OS du visiteur (ex: Mozilla/5.0 Chrome/...)
 *   city       → ville (null pour l'instant, rempli plus tard avec GeoLite2)
 */
#[ORM\Entity(repositoryClass: PageVisitRepository::class)]
#[ORM\Table(name: 'page_visits')]
class PageVisit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $visitedAt;

    #[ORM\Column(type: 'string', length: 500)]
    private string $page;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $referrer = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $userAgent = null;

    // Réservé pour GeoLite2 — nullable, sera rempli plus tard
    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $city = null;

    public function __construct()
    {
        $this->visitedAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getVisitedAt(): \DateTimeImmutable { return $this->visitedAt; }
    public function setVisitedAt(\DateTimeImmutable $v): static { $this->visitedAt = $v; return $this; }

    public function getPage(): string { return $this->page; }
    public function setPage(string $v): static { $this->page = $v; return $this; }

    public function getIp(): ?string { return $this->ip; }
    public function setIp(?string $v): static { $this->ip = $v; return $this; }

    public function getReferrer(): ?string { return $this->referrer; }
    public function setReferrer(?string $v): static { $this->referrer = $v; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $v): static { $this->userAgent = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = $v; return $this; }
}
