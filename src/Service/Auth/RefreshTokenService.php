<?php

namespace App\Service\Auth;

use App\Entity\Admin;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\AdminRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

class RefreshTokenService
{
    // Nom du cookie qui stocke le refresh token
    public const COOKIE_NAME = 'refresh_token';

    public function __construct(
        private readonly RefreshTokenRepository  $refreshTokenRepository,
        private readonly UserRepository          $userRepository,
        private readonly AdminRepository         $adminRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    // ── Création d'un refresh token ─────────────────────────────────────

    /**
     * Crée un RefreshToken en base et retourne le Cookie HttpOnly à envoyer.
     * Appelé après chaque connexion réussie (register, login).
     */
    public function createForUser(User $user, Request $request): array
    {
        return $this->create(
            userId:   $user->getId(),
            userType: RefreshToken::TYPE_USER,
            request:  $request,
        );
    }

    public function createForAdmin(Admin $admin, Request $request): array
    {
        return $this->create(
            userId:   $admin->getId(),
            userType: RefreshToken::TYPE_ADMIN,
            request:  $request,
        );
    }

    private function create(int $userId, string $userType, Request $request): array
    {
        // Génère le token en clair + son hash
        ['plain' => $plain, 'hash' => $hash] = RefreshToken::generate();

        // Stocke le hash en base
        $refreshToken = new RefreshToken();
        $refreshToken->setUserId($userId);
        $refreshToken->setUserType($userType);
        $refreshToken->setTokenHash($hash);
        $refreshToken->setCreatedFromIp($request->getClientIp());
        $refreshToken->setUserAgent(
            substr($request->headers->get('User-Agent', ''), 0, 255)
        );

        $this->refreshTokenRepository->save($refreshToken, flush: true);

        // Crée le cookie HttpOnly — invisible au JavaScript
        $cookie = $this->buildCookie($plain);

        return [
            'refreshToken' => $refreshToken,
            'cookie'       => $cookie,
        ];
    }

    // ── Rotation du token (refresh) ─────────────────────────────────────

    /**
     * Valide le refresh token depuis le cookie,
     * génère un nouveau access token JWT,
     * effectue la rotation (ancien token = consommé, nouveau token créé).
     *
     * @throws \DomainException si le token est invalide/expiré/révoqué
     */
    public function rotate(Request $request): array
    {
        // Lit le token depuis le cookie
        $plainToken = $request->cookies->get(self::COOKIE_NAME);

        if (!$plainToken) {
            throw new \DomainException('Refresh token manquant.');
        }

        // Cherche le token par son hash
        $hash         = hash('sha256', $plainToken);
        $refreshToken = $this->refreshTokenRepository->findValidByHash($hash);

        if (!$refreshToken) {
            throw new \DomainException('Refresh token invalide ou expiré.');
        }

        // Marque l'ancien token comme utilisé (rotation — un token = une utilisation)
        $refreshToken->markAsUsed();
        $this->refreshTokenRepository->save($refreshToken, flush: true);

        // Charge l'utilisateur selon le type
        if ($refreshToken->getUserType() === RefreshToken::TYPE_USER) {
            $user = $this->userRepository->find($refreshToken->getUserId());
            if (!$user || $user->isBlocked()) {
                throw new \DomainException('Compte utilisateur invalide.');
            }
            // Génère un nouveau access token JWT
            $newAccessToken = $this->jwtManager->create($user);

            // Crée un nouveau refresh token (rotation)
            ['plain' => $newPlain, 'hash' => $newHash] = RefreshToken::generate();
            $newRefresh = new RefreshToken();
            $newRefresh->setUserId($user->getId());
            $newRefresh->setUserType(RefreshToken::TYPE_USER);
            $newRefresh->setTokenHash($newHash);
            $newRefresh->setCreatedFromIp($request->getClientIp());
            $newRefresh->setUserAgent(
                substr($request->headers->get('User-Agent', ''), 0, 255)
            );
            $this->refreshTokenRepository->save($newRefresh, flush: true);

            return [
                'accessToken' => $newAccessToken,
                'cookie'      => $this->buildCookie($newPlain),
                'userType'    => RefreshToken::TYPE_USER,
                'user'        => $user,
            ];
        }

        // Admin
        $admin = $this->adminRepository->find($refreshToken->getUserId());
        if (!$admin || !$admin->isActive()) {
            throw new \DomainException('Compte administrateur invalide.');
        }

        $newAccessToken = $this->jwtManager->create($admin);

        ['plain' => $newPlain, 'hash' => $newHash] = RefreshToken::generate();
        $newRefresh = new RefreshToken();
        $newRefresh->setUserId($admin->getId());
        $newRefresh->setUserType(RefreshToken::TYPE_ADMIN);
        $newRefresh->setTokenHash($newHash);
        $newRefresh->setCreatedFromIp($request->getClientIp());
        $newRefresh->setUserAgent(
            substr($request->headers->get('User-Agent', ''), 0, 255)
        );
        $this->refreshTokenRepository->save($newRefresh, flush: true);

        return [
            'accessToken' => $newAccessToken,
            'cookie'      => $this->buildCookie($newPlain),
            'userType'    => RefreshToken::TYPE_ADMIN,
            'admin'       => $admin,
        ];
    }

    // ── Révocation (logout) ─────────────────────────────────────────────

    /**
     * Révoque tous les refresh tokens de l'utilisateur connecté.
     * Retourne un cookie vide pour effacer le cookie côté navigateur.
     */
    public function revokeAll(int $userId, string $userType): Cookie
    {
        $this->refreshTokenRepository->revokeAllForUser($userId, $userType);
        return $this->clearCookie();
    }

    // ── Helpers privés ──────────────────────────────────────────────────

    /**
     * Construit le cookie HttpOnly sécurisé.
     *
     * HttpOnly  = invisible au JavaScript → protège contre XSS
     * Secure    = HTTPS uniquement en production
     * SameSite  = Strict → protège contre CSRF
     */
    private function buildCookie(string $plainToken): Cookie
    {
        $isProduction = $_ENV['APP_ENV'] === 'prod';

        // Architecture de déploiement :
        //
        // DEV  : localhost:5173 (Vite) → proxy → localhost:8000 (Symfony)
        //        Même origine côté navigateur → SameSite=Lax ✅
        //        HTTP → Secure=false obligatoire
        //
        // PROD (Verpex) : www.donaldgros.com + api.donaldgros.com
        //        Même domaine racine (eTLD+1) → "same-site" pour les navigateurs
        //        → SameSite=Lax ✅ (les sous-domaines sont toujours same-site)
        //        HTTPS → Secure=true obligatoire

        return Cookie::create(self::COOKIE_NAME)
            ->withValue($plainToken)
            ->withExpires(
                new \DateTimeImmutable(
                    sprintf('+%d days', RefreshToken::TTL_DAYS)
                )
            )
            ->withPath('/')
            ->withDomain(null)
            ->withSecure($isProduction)  // false en dev (HTTP), true en prod (HTTPS)
            ->withHttpOnly(true)         // invisible au JS → protège contre XSS
            ->withSameSite('Lax');       // Lax = parfait pour même domaine et sous-domaines
    }

    /**
     * Cookie vide pour effacer le refresh token côté navigateur.
     */
    private function clearCookie(): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue('')
            ->withExpires(new \DateTimeImmutable('-1 day'))
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSameSite('Lax');
    }
}