<?php

namespace App\DTO\Order;

use App\Entity\Order;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Annotation\SerializedName;

/**
 * DTO principal reçu lors de la création d'une commande.
 *
 * Couvre les deux chemins du tunnel de commande :
 *
 *  Chemin A — Payer Maintenant (MTN ou Orange via PawaPay)
 *  {
 *    "items": [{ "variantId": 10, "quantity": 2 }],
 *    "paymentMethod": "mtn_momo",          // ou "orange_money"
 *    "payerPhone": "+237699123456",        // numéro pour le paiement mobile
 *    "promoCode": "DG2025",                // optionnel
 *    "deliveryAddress": { ... }
 *  }
 *
 *  Chemin B — Payer à la Livraison
 *  {
 *    "items": [{ "variantId": 10, "quantity": 2 }],
 *    "paymentMethod": "cash_on_delivery",
 *    "promoCode": null,
 *    "deliveryAddress": { ... }
 *  }
 */
final class CreateOrderDTO
{
    #[Assert\NotBlank(message: 'Le panier ne peut pas être vide.')]
    #[Assert\Count(min: 1, minMessage: 'Le panier doit contenir au moins un article.')]
    #[Assert\Valid]
    /** @var OrderItemInputDTO[] */
    public array $items = [];

    #[Assert\NotBlank(message: 'Le mode de paiement est obligatoire.')]
    #[Assert\Choice(
        choices: [Order::PAYMENT_MTN, Order::PAYMENT_ORANGE, Order::PAYMENT_CASH],
        message: 'Mode de paiement invalide.',
    )]
    public string $paymentMethod = '';

    // Obligatoire uniquement si paymentMethod = mtn_momo ou orange_money
    // Validé conditionnellement dans le service (pas via Assert ici,
    // car la règle dépend d'un autre champ)
    #[Assert\Regex(
        pattern: '/^\+?237[0-9]{8,9}$/',
        message: 'Numéro camerounais invalide. Ex : +237699123456',
        groups: ['mobile_payment'],
    )]
    public ?string $payerPhone = null;

    public ?string $promoCode = null;

    #[Assert\NotBlank(message: 'L\'adresse de livraison est obligatoire.')]
    #[Assert\Valid]
    public ?DeliveryAddressDTO $deliveryAddress = null;

    public ?int $deliveryFee = null;

    // ── Factory ───────────────────────────────────────────────────────────

    /**
     * Hydrate le DTO depuis un tableau PHP brut (issu de json_decode).
     * Permet de contourner la limite du sérialiseur Symfony
     * qui ne gère pas les objets imbriqués sans métadonnées explicites.
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();

        // items : tableau de OrderItemInputDTO
        $dto->items = array_map(static function (mixed $item): OrderItemInputDTO {
            $i           = new OrderItemInputDTO();
            $i->variantId = isset($item['variantId']) ? (int) $item['variantId'] : null;
            $i->quantity  = isset($item['quantity'])  ? (int) $item['quantity']  : 1;
            return $i;
        }, (array) ($data['items'] ?? []));

        $dto->paymentMethod = (string) ($data['paymentMethod'] ?? '');
        $dto->payerPhone    = isset($data['payerPhone']) ? (string) $data['payerPhone'] : null;
        $dto->promoCode     = isset($data['promoCode'])  ? (string) $data['promoCode']  : null;
        $dto->deliveryFee   = isset($data['deliveryFee']) ? (int) $data['deliveryFee'] : null;

        // deliveryAddress : DeliveryAddressDTO
        if (!empty($data['deliveryAddress']) && is_array($data['deliveryAddress'])) {
            $addr               = new DeliveryAddressDTO();
            $addr->firstName    = (string) ($data['deliveryAddress']['firstName']    ?? '');
            $addr->lastName     = (string) ($data['deliveryAddress']['lastName']     ?? '');
            $addr->phone        = (string) ($data['deliveryAddress']['phone']        ?? '');
            $addr->city         = (string) ($data['deliveryAddress']['city']         ?? '');
            $addr->district     = (string) ($data['deliveryAddress']['district']     ?? '');
            $addr->street       = isset($data['deliveryAddress']['street'])       ? (string) $data['deliveryAddress']['street']       : null;
            $addr->instructions = isset($data['deliveryAddress']['instructions']) ? (string) $data['deliveryAddress']['instructions'] : null;
            $dto->deliveryAddress = $addr;
        }

        return $dto;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function isMobilePayment(): bool
    {
        return in_array($this->paymentMethod, [Order::PAYMENT_MTN, Order::PAYMENT_ORANGE], true);
    }

    public function isCashOnDelivery(): bool
    {
        return $this->paymentMethod === Order::PAYMENT_CASH;
    }

    /**
     * Retourne le provider PawaPay exact attendu par l'API
     * selon le mode de paiement choisi.
     */
    public function getPawapayProvider(): ?string
    {
        return match ($this->paymentMethod) {
            Order::PAYMENT_MTN    => \App\Entity\Payment::PROVIDER_MTN_CMR,
            Order::PAYMENT_ORANGE => \App\Entity\Payment::PROVIDER_ORANGE_CMR,
            default               => null,
        };
    }
}