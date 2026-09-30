<?php

namespace App\DTO\Order;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un article du panier envoyé par React lors de la création de commande.
 */
final class OrderItemInputDTO
{
    #[Assert\NotNull(message: "L'identifiant de la variante est obligatoire.")]
    #[Assert\Positive(message: "L'identifiant de variante doit être un entier positif.")]
    public ?int $variantId = null;

    #[Assert\NotBlank(message: 'La quantité est obligatoire.')]
    #[Assert\Positive(message: 'La quantité doit être supérieure à 0.')]
    #[Assert\LessThanOrEqual(99, message: 'Quantité maximum : 99 par article.')]
    public int $quantity = 1;
}
