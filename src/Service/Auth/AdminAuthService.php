<?php

namespace App\Service\Auth;

use App\DTO\Auth\AdminLoginDTO;
use App\Entity\Admin;
use App\Repository\AdminRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminAuthService
{
    public function __construct(
        private readonly AdminRepository             $adminRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface    $jwtManager,
        private readonly RefreshTokenService         $refreshTokenService,
    ) {}

    public function login(AdminLoginDTO $dto, Request $request): array
    {
        $admin = $this->adminRepository->findByEmail($dto->email);

        if (!$admin)              throw new \DomainException('Identifiants incorrects.');
        if (!$admin->isActive())  throw new \DomainException('Ce compte administrateur est désactivé.');
        if (!$this->hasher->isPasswordValid($admin, $dto->password)) throw new \DomainException('Identifiants incorrects.');

        $admin->setLastLoginAt(new \DateTimeImmutable());
        $this->adminRepository->save($admin, flush: true);

        $accessToken = $this->jwtManager->create($admin);
        ['cookie' => $cookie] = $this->refreshTokenService->createForAdmin($admin, $request);

        return ['accessToken' => $accessToken, 'cookie' => $cookie, 'admin' => $this->formatAdmin($admin)];
    }

    public function formatAdmin(Admin $admin): array
    {
        return [
            'id'                 => $admin->getId(),
            'firstName'          => $admin->getFirstName(),
            'lastName'           => $admin->getLastName(),
            'fullName'           => $admin->getFullName(),
            'email'              => $admin->getEmail(),
            'role'               => $admin->getRole(),
            'isSuperAdmin'       => $admin->isSuperAdmin(),
            'mustChangePassword' => $admin->mustChangePassword(),
            'lastLoginAt'        => $admin->getLastLoginAt()?->format('Y-m-d H:i:s'),
        ];
    }
}