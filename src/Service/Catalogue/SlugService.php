<?php

namespace App\Service\Catalogue;

use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;

class SlugService
{
    public function __construct(
        private readonly ProductRepository  $productRepository,
        private readonly CategoryRepository $categoryRepository,
    ) {}

    /**
     * Génère un slug unique pour un produit.
     * Ex : "Chemise Homme Bleu" → "chemise-homme-bleu"
     * Si le slug existe déjà → "chemise-homme-bleu-2", "chemise-homme-bleu-3"...
     */
    public function generateForProduct(string $name, ?int $excludeId = null): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $i    = 2;

        while ($this->productRepository->slugExists($slug, $excludeId)) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Génère un slug unique pour une catégorie.
     */
    public function generateForCategory(string $name, ?int $excludeId = null): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $i    = 2;

        while ($this->categoryRepository->slugExists($slug, $excludeId)) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Convertit une chaîne en slug URL-friendly.
     * Gère les caractères accentués français.
     */
    public function slugify(string $text): string
    {
        if (class_exists(\Symfony\Component\String\Slugger\AsciiSlugger::class)) {
            $slugger = new \Symfony\Component\String\Slugger\AsciiSlugger();
            $text = $slugger->slug($text)->lower()->toString();
        } else {
            // Fallback s'il n'y a ni intl ni String component
            $text = preg_replace('/[^a-zA-Z0-9\s-]/', '', strtolower($text));
            $text = preg_replace('/[\s-]+/', '-', $text);
            $text = trim($text, '-');
        }

        // Limite la longueur à 200 caractères
        return substr($text, 0, 200);
    }
}