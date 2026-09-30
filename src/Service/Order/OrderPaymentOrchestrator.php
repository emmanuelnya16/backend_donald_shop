<?php

namespace App\Service\Order;

use App\DTO\Order\CreateOrderDTO;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Service\Notification\SmsService;
use App\Service\Payment\PawapayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestre la création d'une commande, l'initiation du paiement PawaPay
 * et l'envoi des notifications SMS — en une seule opération cohérente.
 */
class OrderPaymentOrchestrator
{
    public function __construct(
        private readonly OrderService           $orderService,
        private readonly PawapayService          $pawapayService,
        private readonly SmsService              $smsService,
        private readonly EntityManagerInterface  $em,
    ) {}

    /**
     * Crée la commande. Si paiement mobile → initie le dépôt PawaPay immédiatement.
     * Si cash → confirme directement et notifie client + admin par SMS.
     *
     * @return array{order: Order, payment: Payment, requiresPolling: bool}
     *
     * @throws \DomainException
     */
    public function createOrderWithPayment(CreateOrderDTO $dto, ?User $user): array
    {
        $result  = $this->orderService->createOrder($dto, $user);
        $order   = $result['order'];
        $payment = $result['payment'];

        // ── Chemin Cash — confirmation et notifications immédiates ────────
        if ($dto->isCashOnDelivery()) {
            // SMS au client — confirmation de la commande cash
            $this->smsService->notifyClientCashOrderRegistered($order);

            // SMS à l'admin — nouvelle commande à traiter
            $this->smsService->notifyAdminNewOrder($order);

            return [
                'order'           => $order,
                'payment'         => $payment,
                'requiresPolling' => false,
            ];
        }

        // ── Chemin Mobile — initiation PawaPay ─────────────────────────────
        $depositId = Uuid::v4()->toRfc4122();
        $payment->setDepositId($depositId);
        $this->em->flush();

        $pawapayResult = $this->pawapayService->initiateDeposit(
            depositId: $depositId,
            amount:    $payment->getAmount(),
            phone:     $dto->payerPhone,
            provider:  $result['pawapayProvider'],
        );

        if (!$pawapayResult['success']) {
            $payment->markAsFailed(null, $pawapayResult['error'] ?? 'Échec de l\'initiation du paiement.');
            $this->orderService->markPaymentFailed($order);
            $this->em->flush();

            throw new \DomainException(
                $pawapayResult['error'] ?? 'Impossible d\'initier le paiement. Veuillez réessayer.'
            );
        }

        $payment->setProviderResponse($pawapayResult['data'] ?? []);
        $payment->updateFromPawapayStatus($pawapayResult['status'] ?? 'ACCEPTED');
        $this->em->flush();

        return [
            'order'           => $order,
            'payment'         => $payment,
            'requiresPolling' => true,
        ];
    }

    /**
     * Vérifie le statut du paiement auprès de PawaPay, met à jour la commande
     * et envoie les SMS appropriés dès que le statut devient final.
     */
    public function pollPaymentStatus(Order $order): array
    {
        $payment = $order->getLatestPayment();

        if (!$payment || !$payment->getDepositId()) {
            throw new \DomainException('Aucun paiement mobile associé à cette commande.');
        }

        if (!$payment->isPending()) {
            return [
                'order'      => $order,
                'payment'    => $payment,
                'isFinal'    => true,
                'statusInfo' => [
                    'title' => $payment->isConfirmed() ? 'Paiement réussi' : 'Paiement échoué',
                    'color' => $payment->isConfirmed() ? 'success' : 'error',
                ],
            ];
        }

        $order->incrementPollAttempts();
        $payment->incrementStatusCheck();

        $result = $this->pawapayService->checkDepositStatus($payment->getDepositId());

        if (!$result['success']) {
            $this->em->flush();
            return [
                'order'      => $order,
                'payment'    => $payment,
                'isFinal'    => false,
                'statusInfo' => ['title' => 'Vérification en cours', 'color' => 'info'],
            ];
        }

        $payment->setProviderResponse($result['data'] ?? []);
        $payment->updateFromPawapayStatus($result['status']);

        if ($result['isFinal']) {
            if ($payment->isConfirmed()) {
                $this->orderService->confirmOrder($order);

                // SMS au client — paiement confirmé
                $this->smsService->notifyClientOrderConfirmed($order);
                // SMS à l'admin — nouvelle commande payée
                $this->smsService->notifyAdminNewOrder($order);

            } else {
                $failureDetails = $this->pawapayService->extractFailureDetails($result['data'] ?? []);
                $payment->setFailureCode($failureDetails['code']);
                $payment->setFailureMessage($failureDetails['message']);
                $this->orderService->markPaymentFailed($order);

                // SMS au client — paiement échoué
                $this->smsService->notifyClientPaymentFailed($order);
            }
        }

        $this->em->flush();

        return [
            'order'      => $order,
            'payment'    => $payment,
            'isFinal'    => $result['isFinal'],
            'statusInfo' => $result['statusInfo'],
        ];
    }
}