<?php

namespace App\Controller\Api\Auth;

use App\Controller\Api\AbstractApiController;
use App\DTO\Auth\UserLoginDTO;
use App\DTO\Auth\UserRegisterDTO;
use App\Entity\User;
use App\Service\Auth\UserAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/auth', name: 'api_auth_')]
class UserAuthController extends AbstractApiController
{
    public function __construct(
        private readonly UserAuthService $authService,
        \Symfony\Component\Validator\Validator\ValidatorInterface  $validator,
        \Symfony\Component\Serializer\SerializerInterface          $serializer,
    ) {
        parent::__construct($validator, $serializer);
    }

    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, UserRegisterDTO::class);
        if (!$dto) return $this->error('Corps JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        try {
            $result = $this->authService->register($dto, $request);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        // Réponse avec le cookie refresh token attaché
        $response = $this->created([
            'accessToken' => $result['accessToken'],
            'user'        => $result['user'],
        ], 'Compte créé avec succès. Bienvenue chez Donald Gros !');

        $response->headers->setCookie($result['cookie']);
        return $response;
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, UserLoginDTO::class);
        if (!$dto) return $this->error('Corps JSON invalide.', Response::HTTP_BAD_REQUEST);

        $errors = $this->validate($dto);
        if ($errors) return $this->error('Données invalides.', Response::HTTP_UNPROCESSABLE_ENTITY, $errors);

        try {
            $result = $this->authService->login($dto, $request);
        } catch (\DomainException $e) {
            $status = str_contains($e->getMessage(), 'suspendu')
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_UNAUTHORIZED;
            return $this->error($e->getMessage(), $status);
        }

        // Réponse avec le cookie refresh token attaché
        $response = $this->success([
            'accessToken' => $result['accessToken'],
            'user'        => $result['user'],
        ], 'Connexion réussie.');

        $response->headers->setCookie($result['cookie']);
        return $response;
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) return $this->unauthorized();

        return $this->success([
            'id'        => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName'  => $user->getLastName(),
            'fullName'  => $user->getFullName(),
            'phone'     => $user->getPhone(),
            'city'      => $user->getCity(),
            'status'    => $user->getStatus(),
        ]);
    }
}