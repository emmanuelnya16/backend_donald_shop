<?php

namespace App\Service\Notification;

use App\Entity\Order;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Exceptions\TwilioException;

/**
 * Service d'envoi de SMS via Twilio.
 *
 * IMPORTANT — Compte Twilio Trial :
 * En mode essai, les SMS ne peuvent être envoyés QUE vers des numéros
 * vérifiés dans la console Twilio. En production, un compte payant
 * lève cette limitation.
 */
class SmsService
{
    private TwilioClient $client;

    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $fromNumber,    // numéro Twilio (ex: +1XXXXXXXXXX)
        private readonly string $adminPhone,    // numéro de l'admin à notifier
    ) {
        $this->client = new TwilioClient($this->accountSid, $this->authToken);
    }

    // ── Envoi générique ────────────────────────────────────────────────────

    /**
     * Envoie un SMS brut. Retourne true si l'envoi a réussi.
     * Ne lève jamais d'exception — un échec SMS ne doit jamais
     * bloquer le flux principal de la commande.
     */
    public function send(string $toPhone, string $message): bool
    {
        try {
            $this->client->messages->create(
                $this->normalizePhone($toPhone),
                [
                    'from' => $this->fromNumber,
                    'body' => $message,
                ]
            );
            return true;
        } catch (TwilioException $e) {
            // En trial : erreur fréquente si le numéro destinataire
            // n'est pas vérifié dans la console Twilio
            error_log('Échec envoi SMS Twilio : ' . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log('Erreur inattendue SMS : ' . $e->getMessage());
            return false;
        }
    }

    // ── Notifications liées aux commandes ─────────────────────────────────

    /**
     * SMS envoyé au client quand son paiement mobile est confirmé.
     */
    public function notifyClientOrderConfirmed(Order $order): bool
    {
        $address = $order->getDeliveryAddress();
        if (!$address) return false;

        $message = sprintf(
            "Donald Gros : Bonjour %s, votre commande #%s a ete confirmee ! Montant : %s FCFA. Livraison estimee 2-5 jours. Merci de votre confiance !",
            $address->getFirstName(),
            $order->getOrderNumber(),
            number_format($order->getTotalAmount(), 0, ',', ' ')
        );

        return $this->send($address->getPhone(), $message);
    }

    /**
     * SMS envoyé au client quand sa commande cash à la livraison est enregistrée.
     */
    public function notifyClientCashOrderRegistered(Order $order): bool
    {
        $address = $order->getDeliveryAddress();
        if (!$address) return false;

        $message = sprintf(
            "Donald Gros : Bonjour %s, votre commande #%s est enregistree. Preparez %s FCFA pour le livreur. Livraison estimee 2-5 jours.",
            $address->getFirstName(),
            $order->getOrderNumber(),
            number_format($order->getTotalAmount(), 0, ',', ' ')
        );

        return $this->send($address->getPhone(), $message);
    }

    /**
     * SMS envoyé au client quand son paiement mobile a échoué.
     */
    public function notifyClientPaymentFailed(Order $order): bool
    {
        $address = $order->getDeliveryAddress();
        if (!$address) return false;

        $message = sprintf(
            "Donald Gros : Le paiement de votre commande #%s a echoue. Vous pouvez reessayer depuis le site ou choisir le paiement a la livraison.",
            $order->getOrderNumber()
        );

        return $this->send($address->getPhone(), $message);
    }

    /**
     * SMS envoyé au client à chaque changement de statut de commande
     * (en préparation, expédiée, livrée) — déclenché depuis le back-office.
     */
    public function notifyClientStatusUpdate(Order $order): bool
    {
        $address = $order->getDeliveryAddress();
        if (!$address) return false;

        $messages = [
            Order::STATUS_CONFIRMED  => "Votre commande #%s a ete confirmee ! Livraison estimee 2-5 jours. Merci pour votre confiance.",
            Order::STATUS_PROCESSING => "Votre commande #%s est en cours de preparation.",
            Order::STATUS_SHIPPED    => "Votre commande #%s est en route ! Le livreur vous contactera bientot.",
            Order::STATUS_DELIVERED  => "Votre commande #%s a ete livree. Merci ! Laissez-nous un avis sur donaldgros.com.",
            Order::STATUS_CANCELLED  => "Votre commande #%s a ete annulee. Contactez-nous pour plus d'infos.",
        ];

        $template = $messages[$order->getStatus()] ?? null;
        if (!$template) return false;

        $message = "Donald Gros : " . sprintf($template, $order->getOrderNumber());

        return $this->send($address->getPhone(), $message);
    }

    /**
     * SMS envoyé à l'admin pour CHAQUE nouvelle commande confirmée.
     * Lui permet de suivre l'activité même hors back-office.
     */
    public function notifyAdminNewOrder(Order $order): bool
    {
        $address  = $order->getDeliveryAddress();
        $payment  = $order->getLatestPayment();

        $message = sprintf(
            "Nouvelle commande #%s — %s FCFA — %s — Client : %s (%s) — Ville : %s",
            $order->getOrderNumber(),
            number_format($order->getTotalAmount(), 0, ',', ' '),
            $payment?->getProviderLabel() ?? $order->getPaymentMethod(),
            $address?->getFullName() ?? 'N/A',
            $address?->getPhone() ?? 'N/A',
            $address?->getCity() ?? 'N/A'
        );

        return $this->send($this->adminPhone, $message);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Normalise le téléphone au format international requis par Twilio : +237XXXXXXXXX
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        if (str_starts_with($phone, '+237')) return $phone;
        if (str_starts_with($phone, '237'))  return '+' . $phone;
        if (str_starts_with($phone, '+'))    return $phone; // autre pays
        return '+237' . $phone;
    }
}