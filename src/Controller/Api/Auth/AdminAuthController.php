<?php

namespace App\Controller\Api\Auth;

use App\Controller\Api\AbstractApiController;
use App\DTO\Admin\ActivateAdminDTO;
use App\DTO\Auth\AdminLoginDTO;
use App\Repository\AdminRepository;
use App\Service\Auth\AdminAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/auth', name: 'api_admin_auth_')]
class AdminAuthController extends AbstractApiController
{
    public function __construct(
        private readonly AdminAuthService $authService,
        \Symfony\Component\Validator\Validator\ValidatorInterface  $validator,
        \Symfony\Component\Serializer\SerializerInterface          $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, AdminLoginDTO::class);
        if (!$dto) return $this->error('Corps JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        try {
            $result = $this->authService->login($dto, $request);
        } catch (\DomainException $e) {
            $status = str_contains($e->getMessage(), 'désactivé')
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_UNAUTHORIZED;
            return $this->error($e->getMessage(), $status);
        }

        // Réponse JSON + cookie HttpOnly refresh token attaché
        $response = $this->success([
            'accessToken' => $result['accessToken'],
            'admin'       => $result['admin'],
        ], 'Connexion administrateur réussie.');

        $response->headers->setCookie($result['cookie']);
        return $response;
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var \App\Entity\Admin $admin */
        $admin = $this->getUser();
        if (!$admin) return $this->unauthorized();

        return $this->success([
            'id'                 => $admin->getId(),
            'firstName'          => $admin->getFirstName(),
            'lastName'           => $admin->getLastName(),
            'fullName'           => $admin->getFullName(),
            'email'              => $admin->getEmail(),
            'role'               => $admin->getRole(),
            'isSuperAdmin'       => $admin->isSuperAdmin(),
            'mustChangePassword' => $admin->mustChangePassword(),
            'lastLoginAt'        => $admin->getLastLoginAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    #[Route('/activate', name: 'activate', methods: ['POST'])]
    public function activate(
        Request $request,
        AdminRepository $adminRepository,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $dto = $this->deserialize($request, ActivateAdminDTO::class);
        if (!$dto) return $this->error('Corps JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        // Trouver l'admin avec le token
        $admin = $adminRepository->findOneBy(['activationToken' => $dto->token]);
        if (!$admin) {
            return $this->error('Jeton d\'activation invalide ou déjà consommé.', Response::HTTP_NOT_FOUND);
        }

        if ($admin->isActivationTokenExpired()) {
            return $this->error('Le jeton d\'activation a expiré (24h).', Response::HTTP_GONE);
        }

        // Activer le compte
        $activated = $admin->activate($dto->token);
        if (!$activated) {
            return $this->error('Erreur lors de l\'activation du compte.', Response::HTTP_BAD_REQUEST);
        }

        // Hacher le nouveau mot de passe
        $admin->setPassword(
            $passwordHasher->hashPassword($admin, $dto->password)
        );
        $admin->setMustChangePassword(false); // Il a défini son mot de passe

        $adminRepository->save($admin, flush: true);

        return $this->success(null, 'Votre compte administrateur a été activé avec succès. Vous pouvez maintenant vous connecter.');
    }
}