<?php

namespace App\Service\Auth;

use App\DTO\Auth\UserLoginDTO;
use App\DTO\Auth\UserRegisterDTO;
use App\Entity\User;
use App\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserAuthService
{
    public function __construct(
        private readonly UserRepository              $userRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface    $jwtManager,
        private readonly RefreshTokenService         $refreshTokenService,
    ) {}

    public function register(UserRegisterDTO $dto, Request $request): array
    {
        if ($this->userRepository->phoneExists($dto->phone)) {
            throw new \DomainException('Ce numéro de téléphone est déjà associé à un compte.');
        }

        $phone = $this->normalizePhone($dto->phone);
        $user  = new User();
        $user->setFirstName(trim($dto->firstName));
        $user->setLastName(trim($dto->lastName));
        $user->setCity(trim($dto->city));
        $user->setPhone($phone);
        $user->setPassword($this->hasher->hashPassword($user, $dto->password));
        $this->userRepository->save($user, flush: true);

        $accessToken = $this->jwtManager->create($user);
        ['cookie' => $cookie] = $this->refreshTokenService->createForUser($user, $request);

        return ['accessToken' => $accessToken, 'cookie' => $cookie, 'user' => $this->formatUser($user)];
    }

    public function login(UserLoginDTO $dto, Request $request): array
    {
        $phone = $this->normalizePhone($dto->phone);
        $user  = $this->userRepository->findByPhone($phone);

        if (!$user)              throw new \DomainException('Identifiants incorrects.');
        if ($user->isBlocked())  throw new \DomainException('Ce compte a été suspendu. Contactez le support.');
        if (!$this->hasher->isPasswordValid($user, $dto->password)) throw new \DomainException('Identifiants incorrects.');

        $accessToken = $this->jwtManager->create($user);
        ['cookie' => $cookie] = $this->refreshTokenService->createForUser($user, $request);

        return ['accessToken' => $accessToken, 'cookie' => $cookie, 'user' => $this->formatUser($user)];
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        if (str_starts_with($phone, '+237')) return $phone;
        if (str_starts_with($phone, '237'))  return '+' . $phone;
        return '+237' . $phone;
    }

    public function formatUser(User $user): array
    {
        return [
            'id'        => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName'  => $user->getLastName(),
            'fullName'  => $user->getFullName(),
            'phone'     => $user->getPhone(),
            'city'      => $user->getCity(),
            'status'    => $user->getStatus(),
            'createdAt' => $user->getCreatedAt()->format('Y-m-d'),
        ];
    }
}