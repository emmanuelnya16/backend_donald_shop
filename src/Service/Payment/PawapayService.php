<?php

namespace App\Service\Payment;

use App\Entity\Payment;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Service d'intégration PawaPay pour le Cameroun.
 *
 * Currency  : toujours XAF
 * Payer type: toujours MMO (Mobile Money Operator)
 * Providers : MTN_MOMO_CMR | ORANGE_MONEY_CMR
 *
 * Fonctionnement choisi : POLLING (pas de webhook).
 * On crée le dépôt, puis on interroge nous-mêmes GET /deposits/{depositId}
 * jusqu'à obtenir un statut final (COMPLETED, FAILED, REJECTED, EXPIRED).
 */
class PawapayService
{
    private HttpClientInterface $client;

    public function __construct(
        private readonly string $baseUrl,      // ex: https://api.sandbox.pawapay.io/v2
        private readonly string $apiToken,     // Bearer token sandbox/production
    ) {
        $this->client = HttpClient::create();
    }

    // ── Création du dépôt ────────────────────────────────────────────────

    /**
     * Initie un dépôt PawaPay (MTN ou Orange).
     *
     * @param string $depositId  UUID généré par nous — sert aussi à vérifier le statut
     * @param int    $amount     Montant en FCFA (entier)
     * @param string $phone      Numéro au format 237XXXXXXXXX (sans le +)
     * @param string $provider   Payment::PROVIDER_MTN_CMR ou Payment::PROVIDER_ORANGE_CMR
     *
     * @return array{success: bool, status?: string, data?: array, error?: string}
     */
    public function initiateDeposit(
        string $depositId,
        int    $amount,
        string $phone,
        string $provider,
    ): array {
        $payload = [
            'depositId' => $depositId,
            'amount'    => $amount,           // ✅ entier (int), pas une string
            'currency'  => Payment::CURRENCY, // toujours "XAF"
            'payer'     => [
                'type'           => 'MMO',
                'accountDetails' => [
                    'phoneNumber' => $this->normalizePhone($phone),
                    'provider'    => $provider, // MTN_MOMO_CMR | ORANGE_CMR
                ],
            ],
        ];

        try {
            $response = $this->client->request('POST', $this->baseUrl . '/deposits', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ],
                'json'    => $payload,
                'timeout' => 30,
            ]);

            $statusCode   = $response->getStatusCode();
            $responseData = json_decode($response->getContent(false), true) ?? [];

            // PawaPay retourne 200/201/202 selon l'état initial du dépôt
            if (in_array($statusCode, [200, 201, 202], true)) {
                return [
                    'success'  => true,
                    'status'   => $responseData['status'] ?? 'ACCEPTED',
                    'data'     => $responseData,
                ];
            }

            // ── DEBUG : log complet pour diagnostic ──────────────────────
            $errorDetail = sprintf(
                '[PawaPay %d] %s | body: %s',
                $statusCode,
                $responseData['message'] ?? $responseData['error'] ?? 'no message',
                json_encode($responseData),
            );
            error_log($errorDetail);

            return [
                'success' => false,
                'error'   => $errorDetail,  // message enrichi visible dans la réponse 400
                'data'    => $responseData,
            ];

        } catch (TransportExceptionInterface $e) {
            return [
                'success' => false,
                'error'   => 'Erreur de connexion à PawaPay : ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => 'Erreur inattendue : ' . $e->getMessage(),
            ];
        }
    }

    // ── Vérification du statut (polling) ─────────────────────────────────

    /**
     * Vérifie le statut d'un dépôt PawaPay.
     * Appelé en boucle par PaymentStatusController pendant le polling React.
     *
     * @return array{
     *   success: bool,
     *   status?: string,        // ACCEPTED|SUBMITTED|PROCESSING|COMPLETED|FAILED|REJECTED|EXPIRED
     *   isFinal?: bool,          // true si COMPLETED/FAILED/REJECTED/EXPIRED
     *   statusInfo?: array,      // titre, message, icône pour affichage React
     *   data?: array,
     *   error?: string,
     * }
     */
    public function checkDepositStatus(string $depositId): array
    {
        try {
            $response = $this->client->request('GET', $this->baseUrl . '/deposits/' . $depositId, [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiToken,
                ],
                'timeout' => 30,
            ]);

            $statusCode   = $response->getStatusCode();
            $responseData = json_decode($response->getContent(false), true) ?? [];

            if ($statusCode === 200) {
                $data   = $responseData['data'] ?? $responseData;
                $status = $data['status'] ?? 'UNKNOWN';

                return [
                    'success'    => true,
                    'status'     => $status,
                    'isFinal'    => $this->isFinalStatus($status),
                    'statusInfo' => $this->getStatusInfo($status, $data),
                    'data'       => $data,
                ];
            }

            return [
                'success' => false,
                'error'   => $responseData['message'] ?? 'Erreur lors de la vérification du statut.',
            ];

        } catch (TransportExceptionInterface $e) {
            return [
                'success' => false,
                'error'   => 'Erreur de connexion : ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => 'Erreur inattendue : ' . $e->getMessage(),
            ];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Un statut final signifie que le polling peut s'arrêter —
     * plus aucun changement n'est attendu de PawaPay après ça.
     */
    public function isFinalStatus(string $status): bool
    {
        return in_array($status, ['COMPLETED', 'FAILED', 'REJECTED', 'EXPIRED'], true);
    }

    /**
     * Normalise le téléphone au format attendu par PawaPay : 237XXXXXXXXX (sans +)
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone); // retire tout sauf les chiffres
        if (!str_starts_with($phone, '237')) {
            $phone = '237' . $phone;
        }
        return $phone;
    }

    /**
     * Traduit le statut brut PawaPay en informations affichables côté React.
     * Utilisé dans la réponse du polling pour piloter l'UI du tunnel de commande.
     */
    private function getStatusInfo(string $status, array $data): array
    {
        $statusMap = [
            'ACCEPTED' => [
                'title'       => 'Paiement accepté',
                'message'     => 'Votre demande de paiement est en cours de traitement.',
                'color'       => 'info',
                'instruction' => 'Veuillez patienter...',
            ],
            'SUBMITTED' => [
                'title'       => 'Paiement soumis',
                'message'     => 'La demande a été envoyée à votre opérateur mobile.',
                'color'       => 'info',
                'instruction' => 'Vérifiez votre téléphone pour confirmer.',
            ],
            'PROCESSING' => [
                'title'       => 'Traitement en cours',
                'message'     => 'Votre paiement est en cours de traitement.',
                'color'       => 'info',
                'instruction' => 'Cela peut prendre quelques instants...',
            ],
            'COMPLETED' => [
                'title'       => 'Paiement réussi',
                'message'     => 'Votre paiement a été confirmé avec succès.',
                'color'       => 'success',
                'instruction' => 'Votre commande est confirmée.',
            ],
            'FAILED' => [
                'title'       => 'Paiement échoué',
                'message'     => $this->getFailureMessage($data),
                'color'       => 'error',
                'instruction' => 'Vous pouvez réessayer ou changer de mode de paiement.',
            ],
            'REJECTED' => [
                'title'       => 'Paiement refusé',
                'message'     => 'Vous avez refusé le paiement sur votre téléphone.',
                'color'       => 'error',
                'instruction' => 'Réessayez si c\'était une erreur.',
            ],
            'EXPIRED' => [
                'title'       => 'Délai expiré',
                'message'     => 'Le délai pour confirmer le paiement a été dépassé.',
                'color'       => 'error',
                'instruction' => 'Veuillez initier une nouvelle tentative.',
            ],
        ];

        return $statusMap[$status] ?? [
            'title'       => 'Statut inconnu',
            'message'     => 'Impossible de déterminer le statut de la transaction.',
            'color'       => 'unknown',
            'instruction' => 'Contactez le support si le problème persiste.',
        ];
    }

    /**
     * Traduit les codes d'échec PawaPay en messages compréhensibles.
     */
    private function getFailureMessage(array $data): string
    {
        $failureReason  = $data['failureReason'] ?? null;
        if (!$failureReason) {
            return 'Le paiement a échoué pour une raison inconnue.';
        }

        $failureCode    = $failureReason['failureCode']    ?? '';
        $failureMessage = $failureReason['failureMessage'] ?? '';

        $errorMessages = [
            'PAYER_NOT_FOUND'       => 'Le numéro de téléphone n\'est pas reconnu par l\'opérateur.',
            'INSUFFICIENT_BALANCE'  => 'Solde insuffisant sur votre compte mobile.',
            'PAYMENT_NOT_APPROVED'  => 'Vous avez refusé le paiement sur votre téléphone.',
            'PAYER_LIMIT_REACHED'   => 'Vous avez atteint votre limite de transaction.',
            'TRANSACTION_TIMEOUT'   => 'Le délai de traitement a expiré.',
            'INVALID_AMOUNT'        => 'Le montant de la transaction est invalide.',
            'SERVICE_UNAVAILABLE'   => 'Le service de paiement est temporairement indisponible.',
        ];

        return $errorMessages[$failureCode] ?? ($failureMessage ?: 'Échec du paiement.');
    }

    /**
     * Extrait le code et le message d'échec depuis la réponse PawaPay
     * pour les stocker dans l'entité Payment.
     */
    public function extractFailureDetails(array $data): array
    {
        $failureReason = $data['failureReason'] ?? null;
        return [
            'code'    => $failureReason['failureCode']    ?? null,
            'message' => $failureReason['failureMessage'] ?? null,
        ];
    }
}