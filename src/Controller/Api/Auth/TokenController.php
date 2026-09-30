<?php

namespace App\Controller\Api\Auth;

use App\Controller\Api\AbstractApiController;
use App\Entity\Admin;
use App\Entity\User;
use App\Service\Auth\RefreshTokenService;
use App\Service\Auth\UserAuthService;
use App\Service\Auth\AdminAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api', name: 'api_token_')]
class TokenController extends AbstractApiController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly UserAuthService     $userAuthService,
        private readonly AdminAuthService    $adminAuthService,
        \Symfony\Component\Validator\Validator\ValidatorInterface  $validator,
        \Symfony\Component\Serializer\SerializerInterface          $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/auth/refresh
    // ─────────────────────────────────────────────────────────────────────
    /**
     * Rafraîchit l'access token JWT via le refresh token en cookie.
     *
     * Pas de body JSON requis.
     * Le refresh token est lu automatiquement depuis le cookie HttpOnly.
     *
     * Réponse 200 :
     * {
     *   "success": true,
     *   "data": {
     *     "accessToken": "eyJ...",   ← nouveau JWT valide 1h
     *     "user": { ... }            ← ou "admin": { ... }
     *   }
     * }
     *
     * Réponse 401 si cookie absent, token expiré ou révoqué.
     */
    #[Route('/auth/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        try {
            $result = $this->refreshTokenService->rotate($request);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        // Prépare la réponse selon le type d'utilisateur
        if ($result['userType'] === 'user') {
            /** @var User $user */
            $user     = $result['user'];
            $data     = [
                'accessToken' => $result['accessToken'],
                'user'        => $this->userAuthService->formatUser($user),
            ];
        } else {
            /** @var Admin $admin */
            $admin = $result['admin'];
            $data  = [
                'accessToken' => $result['accessToken'],
                'admin'       => $this->adminAuthService->formatAdmin($admin),
            ];
        }

        // Attache le nouveau cookie refresh token à la réponse
        $response = $this->success($data, 'Token rafraîchi avec succès.');
        $response->headers->setCookie($result['cookie']);

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/auth/logout       ← logout client
    // POST /api/admin/auth/logout ← logout admin
    // ─────────────────────────────────────────────────────────────────────
    /**
     * Déconnecte l'utilisateur :
     * - Révoque tous ses refresh tokens en base
     * - Efface le cookie HttpOnly côté navigateur
     *
     * L'access token JWT reste techniquement valide jusqu'à expiration (1h)
     * mais le refresh token est révoqué → plus de renouvellement possible.
     * Côté React, l'access token sera supprimé du Context immédiatement.
     */
    #[Route('/auth/logout', name: 'user_logout', methods: ['POST'])]
    public function logoutUser(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->unauthorized();
        }

        $clearCookie = $this->refreshTokenService->revokeAll(
            $user->getId(),
            'user'
        );

        $response = $this->success(null, 'Déconnexion réussie.');
        $response->headers->setCookie($clearCookie);

        return $response;
    }

    #[Route('/admin/auth/logout', name: 'admin_logout', methods: ['POST'])]
    public function logoutAdmin(): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $this->getUser();

        if (!$admin) {
            return $this->unauthorized();
        }

        $clearCookie = $this->refreshTokenService->revokeAll(
            $admin->getId(),
            'admin'
        );

        $response = $this->success(null, 'Déconnexion administrateur réussie.');
        $response->headers->setCookie($clearCookie);

        return $response;
    }
}