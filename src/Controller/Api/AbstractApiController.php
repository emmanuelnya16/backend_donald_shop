<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Contrôleur de base pour tous les controllers API.
 * Fournit des méthodes utilitaires pour :
 *  - retourner des réponses JSON standardisées
 *  - désérialiser et valider les DTOs depuis le body JSON
 */
abstract class AbstractApiController extends AbstractController
{
    public function __construct(
        protected readonly ValidatorInterface    $validator,
        protected readonly SerializerInterface   $serializer,
    ) {}

    // ── Réponses JSON standardisées ───────────────────────────────────────

    /**
     * Réponse succès :
     * { "success": true, "data": {...}, "message": "..." }
     */
    protected function success(
        mixed  $data    = null,
        string $message = 'OK',
        int    $status  = Response::HTTP_OK,
    ): JsonResponse {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Réponse création (201) :
     */
    protected function created(mixed $data = null, string $message = 'Créé avec succès.'): JsonResponse
    {
        return $this->success($data, $message, Response::HTTP_CREATED);
    }

    /**
     * Réponse erreur :
     * { "success": false, "message": "...", "errors": [...] }
     */
    protected function error(
        string $message,
        int    $status = Response::HTTP_BAD_REQUEST,
        array  $errors = [],
    ): JsonResponse {
        return $this->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    /**
     * Réponse 401 Unauthorized.
     */
    protected function unauthorized(string $message = 'Non authentifié.'): JsonResponse
    {
        return $this->error($message, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Réponse 403 Forbidden.
     */
    protected function forbidden(string $message = 'Accès refusé.'): JsonResponse
    {
        return $this->error($message, Response::HTTP_FORBIDDEN);
    }

    /**
     * Réponse 404 Not Found.
     */
    protected function notFound(string $message = 'Ressource introuvable.'): JsonResponse
    {
        return $this->error($message, Response::HTTP_NOT_FOUND);
    }

    // ── JSON body helper ─────────────────────────────────────────────────

    /**
     * Décode le body JSON de la requête en tableau PHP.
     * Retourne un tableau vide si le body est absent ou invalide.
     *
     * Usage :
     *   $body = $this->getJsonBody($request);
     *   $status = $body['status'] ?? null;
     */
    protected function getJsonBody(Request $request): array
    {
        $content = $request->getContent();
        if (empty($content)) return [];

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    // ── DTO helpers ───────────────────────────────────────────────────────

    /**
     * Désérialise le body JSON de la requête vers un DTO typé.
     * Retourne le DTO populé ou null si le JSON est invalide.
     *
     * Usage :
     *   $dto = $this->deserialize($request, UserRegisterDTO::class);
     */
    protected function deserialize(Request $request, string $dtoClass): mixed
    {
        $content = $request->getContent();
        if (empty($content)) return null;

        try {
            return $this->serializer->deserialize($content, $dtoClass, 'json');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Valide un DTO avec Symfony Validator.
     * Retourne un tableau d'erreurs formaté, vide si valide.
     *
     * Usage :
     *   $errors = $this->validate($dto);
     *   if ($errors) return $this->error('Données invalides.', 422, $errors);
     */
    protected function validate(mixed $dto): array
    {
        $violations = $this->validator->validate($dto);
        if (count($violations) === 0) return [];

        $errors = [];
        foreach ($violations as $violation) {
            $field = $violation->getPropertyPath();
            $errors[$field][] = $violation->getMessage();
        }
        return $errors;
    }
}