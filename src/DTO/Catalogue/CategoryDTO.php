<?php

namespace App\DTO\Catalogue;

use Symfony\Component\Validator\Constraints as Assert;

// ── DTO Création / Modification de Catégorie ─────────────────────────────
final class CategoryDTO
{
    #[Assert\NotBlank(message: 'Le nom de la catégorie est obligatoire.')]
    #[Assert\Length(max: 150)]
    public string $name = '';

    #[Assert\Length(max: 150)]
    public ?string $nameEn = null;

    // Slug optionnel — auto-généré depuis le nom si absent
    #[Assert\Length(max: 160)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9-]+$/',
        message: 'Le slug ne peut contenir que des lettres minuscules, chiffres et tirets.'
    )]
    public ?string $slug = null;

    // ID de la catégorie parente — null = catégorie racine
    public ?int $parentId = null;

    public int $position = 0;

    public bool $isActive = true;
}