<?php

namespace App\Service\Order;

use App\DTO\Order\CreateOrderDTO;
use App\DTO\Order\DeliveryAddressDTO;
use App\Entity\DeliveryAddress;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

class OrderService
{
    private const DELIVERY_FEES = [
        'douala'  => 2000,
        'yaounde' => 2500,
    ];
    private const DEFAULT_DELIVERY_FEE = 3500;
    private const MOBILE_PAYMENT_FEE_PERCENT = 1.5;

    public function __construct(
        private readonly OrderRepository          $orderRepository,
        private readonly ProductVariantRepository $variantRepository,
        private readonly PromoCodeRepository      $promoCodeRepository,
        private readonly EntityManagerInterface   $em,
    ) {}

    /**
     * Crée une commande complète à partir du DTO reçu de React.
     *
     * @throws \DomainException si stock insuffisant, variante introuvable, etc.
     */
    public function createOrder(CreateOrderDTO $dto, ?User $user): array
    {
        ['orderItems' => $orderItems, 'itemsTotal' => $itemsTotal] =
            $this->buildOrderItems($dto->items);

        $deliveryFee = $dto->deliveryFee ?? $this->computeDeliveryFee($dto->deliveryAddress->city);

        $discountAmount = 0;
        $promoCodeUsed  = null;
        if ($dto->promoCode) {
            $discountAmount = $this->applyPromoCode($dto->promoCode, $itemsTotal);
            $promoCodeUsed  = strtoupper(trim($dto->promoCode));
        }

        $paymentFee = $dto->isMobilePayment()
            ? (int) round(($itemsTotal - $discountAmount + $deliveryFee) * self::MOBILE_PAYMENT_FEE_PERCENT / 100)
            : 0;

        $totalAmount = $itemsTotal - $discountAmount + $deliveryFee + $paymentFee;

        $order = new Order();
        $order->setOrderNumber($this->orderRepository->generateOrderNumber());
        $order->setUser($user);
        $order->setPaymentMethod($dto->paymentMethod);
        $order->setItemsTotal($itemsTotal);
        $order->setDeliveryFee($deliveryFee);
        $order->setPaymentFee($paymentFee);
        $order->setDiscountAmount($discountAmount);
        $order->setTotalAmount($totalAmount);
        $order->setPromoCodeUsed($promoCodeUsed);
        $order->setStatus(
            $dto->isCashOnDelivery() ? Order::STATUS_PENDING_COD : Order::STATUS_PENDING_PAYMENT
        );

        foreach ($orderItems as $item) {
            $order->addItem($item);
        }

        $address = $this->buildDeliveryAddress($dto->deliveryAddress);
        $order->setDeliveryAddress($address);

        $payment = new Payment();
        $payment->setAmount($totalAmount);
        $payment->setPayerPhone($dto->payerPhone);

        if ($dto->isCashOnDelivery()) {
            $payment->setProvider(Payment::PROVIDER_CASH);
            $payment->setStatus(Payment::STATUS_INITIATED);
        } else {
            $payment->setProvider($dto->getPawapayProvider());
            $payment->setStatus(Payment::STATUS_INITIATED);
        }

        $order->addPayment($payment);

        // ── RÉSERVATION DU STOCK DÈS LA CRÉATION DE LA COMMANDE ──────────
        // C'est le point clé de la correction : on décrémente le stock
        // immédiatement à la création, PAS à la confirmation du paiement.
        // Cela évite qu'un stock devienne insuffisant entre la création
        // de la commande et la confirmation du paiement mobile (qui peut
        // prendre jusqu'à 2 minutes). Si le paiement échoue, on restaure
        // le stock (voir markPaymentFailed()).
        foreach ($orderItems as $item) {
            $variant = $item->getProductVariant();
            if ($variant) {
                // decreaseStock() lève une \DomainException si stock insuffisant
                // — déjà géré par buildOrderItems() en amont, mais on protège
                // aussi ici contre une variation concurrente de dernière minute
                $variant->decreaseStock($item->getQuantity());
            }
        }

        $this->em->persist($order);
        $this->em->flush();

        return [
            'order'           => $order,
            'payment'         => $payment,
            'pawapayProvider' => $dto->getPawapayProvider(),
            'payerPhone'      => $dto->payerPhone,
        ];
    }

    /**
     * Confirme une commande après paiement réussi (mobile ou cash validé par l'admin).
     *
     * Le stock a déjà été décrémenté à la création de la commande (voir createOrder).
     * Cette méthode se contente donc de finaliser le statut et les statistiques —
     * elle ne touche plus au stock, ce qui élimine le risque de DomainException
     * inattendue au moment de la confirmation.
     */
    public function confirmOrder(Order $order): void
    {
        $order->setStatus(Order::STATUS_CONFIRMED);

        // Incrémente les statistiques de vente (sans toucher au stock)
        foreach ($order->getItems() as $item) {
            $variant = $item->getProductVariant();
            if ($variant) {
                $variant->getProduct()->incrementSalesCount($item->getQuantity());
            }
        }

        if ($order->getPromoCodeUsed()) {
            $promoCode = $this->promoCodeRepository->findOneBy(['code' => $order->getPromoCodeUsed()]);
            $promoCode?->incrementUsage();
        }

        $this->em->flush();
    }

    /**
     * Marque une commande comme paiement échoué
     * ET restaure le stock réservé à la création (rollback métier).
     */
    public function markPaymentFailed(Order $order): void
    {
        $order->setStatus(Order::STATUS_PAYMENT_FAILED);

        // Restaure le stock — le paiement n'a pas abouti, l'article
        // redevient disponible pour les autres clients
        foreach ($order->getItems() as $item) {
            $variant = $item->getProductVariant();
            if ($variant) {
                $variant->increaseStock($item->getQuantity());
            }
        }

        $this->em->flush();
    }

    /**
     * Annule une commande déjà confirmée (cash ou mobile) et restaure le stock.
     * Utilisé par l'admin depuis le back-office.
     *
     * NOTE : cette méthode NE flush PAS — c'est le controller appelant
     * qui contrôle le moment du flush (après avoir éventuellement ajouté une note).
     */
    public function cancelOrder(Order $order): void
    {
        if (!$order->canBeCancelled()) {
            throw new \DomainException('Cette commande ne peut plus être annulée.');
        }

        $order->setStatus(Order::STATUS_CANCELLED);

        foreach ($order->getItems() as $item) {
            $variant = $item->getProductVariant();
            if ($variant) {
                $variant->increaseStock($item->getQuantity());
            }
        }
    }

    // ── Formatage pour les réponses JSON ─────────────────────────────────

    public function formatOne(Order $order): array
    {
        return [
            'id'             => $order->getId(),
            'orderNumber'    => $order->getOrderNumber(),
            'status'         => $order->getStatus(),
            'statusLabel'    => $order->getStatusLabel(),
            'paymentMethod'  => $order->getPaymentMethod(),
            'itemsTotal'     => $order->getItemsTotal(),
            'deliveryFee'    => $order->getDeliveryFee(),
            'paymentFee'     => $order->getPaymentFee(),
            'discountAmount' => $order->getDiscountAmount(),
            'totalAmount'    => $order->getTotalAmount(),
            'promoCodeUsed'  => $order->getPromoCodeUsed(),
            'createdAt'      => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'items'          => array_map(fn($item) => [
                'productName'  => $item->getProductName(),
                'variantLabel' => $item->getVariantLabel(),
                'imageUrl'     => $item->getProductImageUrl(),
                'quantity'     => $item->getQuantity(),
                'unitPrice'    => $item->getUnitPrice(),
                'subtotal'     => $item->getSubtotal(),
            ], $order->getItems()->toArray()),
            'deliveryAddress' => $order->getDeliveryAddress() ? [
                'fullName'     => $order->getDeliveryAddress()->getFullName(),
                'phone'        => $order->getDeliveryAddress()->getPhone(),
                'city'         => $order->getDeliveryAddress()->getCity(),
                'district'     => $order->getDeliveryAddress()->getDistrict(),
                'street'       => $order->getDeliveryAddress()->getStreet(),
                'instructions' => $order->getDeliveryAddress()->getInstructions(),
                'formatted'    => $order->getDeliveryAddress()->getFormattedAddress(),
            ] : null,
            'latestPayment' => $order->getLatestPayment() ? [
                'provider'  => $order->getLatestPayment()->getProvider(),
                'status'    => $order->getLatestPayment()->getStatus(),
                'depositId' => $order->getLatestPayment()->getDepositId(),
            ] : null,
        ];
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    /**
     * Valide chaque article du panier et construit les OrderItem.
     *
     * @throws \DomainException
     */
    private function buildOrderItems(array $itemDTOs): array
    {
        $orderItems = [];
        $itemsTotal = 0;

        foreach ($itemDTOs as $itemDTO) {
            $variant = $this->variantRepository->find($itemDTO->variantId);

            if (!$variant || !$variant->isActive()) {
                throw new \DomainException(
                    "Le produit demandé (variante #{$itemDTO->variantId}) n'est plus disponible."
                );
            }

            if ($variant->getStock() < $itemDTO->quantity) {
                throw new \DomainException(
                    sprintf(
                        'Stock insuffisant pour "%s" (%s). Disponible : %d, demandé : %d.',
                        $variant->getProduct()->getName(),
                        $variant->getLabel(),
                        $variant->getStock(),
                        $itemDTO->quantity
                    )
                );
            }

            $orderItem    = OrderItem::fromVariant($variant, $itemDTO->quantity);
            $orderItems[] = $orderItem;
            $itemsTotal  += $orderItem->getSubtotal();
        }

        return ['orderItems' => $orderItems, 'itemsTotal' => $itemsTotal];
    }

    private function computeDeliveryFee(string $city): int
    {
        $cityKey = strtolower(trim($city));
        return self::DELIVERY_FEES[$cityKey] ?? self::DEFAULT_DELIVERY_FEE;
    }

    /**
     * @throws \DomainException si le code est invalide
     */
    private function applyPromoCode(string $code, int $itemsTotal): int
    {
        $promoCode = $this->promoCodeRepository->findOneBy(['code' => strtoupper(trim($code))]);

        if (!$promoCode) {
            throw new \DomainException('Code promo introuvable.');
        }

        if (!$promoCode->isValid()) {
            throw new \DomainException('Ce code promo n\'est plus valide ou a expiré.');
        }

        if ($itemsTotal < $promoCode->getMinOrderAmount()) {
            throw new \DomainException(
                sprintf('Ce code nécessite un montant minimum de %d FCFA.', $promoCode->getMinOrderAmount())
            );
        }

        return $promoCode->computeDiscount($itemsTotal);
    }

    private function buildDeliveryAddress(DeliveryAddressDTO $dto): DeliveryAddress
    {
        $address = new DeliveryAddress();
        $address->setFirstName(trim($dto->firstName));
        $address->setLastName(trim($dto->lastName));
        $address->setPhone($this->normalizePhone($dto->phone));
        $address->setCity(trim($dto->city));
        $address->setDistrict(trim($dto->district));
        $address->setStreet($dto->street ? trim($dto->street) : null);
        $address->setInstructions($dto->instructions);
        return $address;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        if (str_starts_with($phone, '+237')) return $phone;
        if (str_starts_with($phone, '237'))  return '+' . $phone;
        return '+237' . $phone;
    }
}