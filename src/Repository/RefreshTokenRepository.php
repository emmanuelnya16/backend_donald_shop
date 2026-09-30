<?php

namespace App\Repository;

use App\Entity\RefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    // Cherche un token valide par son hash
    public function findValidByHash(string $hash): ?RefreshToken
    {
        return $this->createQueryBuilder('rt')
            ->where('rt.tokenHash = :hash')
            ->andWhere('rt.isUsed = false')
            ->andWhere('rt.isRevoked = false')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('hash', $hash)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Révoque TOUS les tokens d'un utilisateur
    // Utilisé au logout pour invalider toutes les sessions
    public function revokeAllForUser(int $userId, string $userType): int
    {
        return $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.isRevoked', 'true')
            ->where('rt.userId = :userId')
            ->andWhere('rt.userType = :userType')
            ->andWhere('rt.isRevoked = false')
            ->setParameter('userId', $userId)
            ->setParameter('userType', $userType)
            ->getQuery()
            ->execute();
    }

    // Supprime les tokens expirés — à appeler via une commande de nettoyage
    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    // Compte les tokens actifs d'un utilisateur
    // Utile pour détecter des sessions suspectes
    public function countActiveForUser(int $userId, string $userType): int
    {
        return (int) $this->createQueryBuilder('rt')
            ->select('COUNT(rt.id)')
            ->where('rt.userId = :userId')
            ->andWhere('rt.userType = :userType')
            ->andWhere('rt.isRevoked = false')
            ->andWhere('rt.isUsed = false')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('userId', $userId)
            ->setParameter('userType', $userType)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(RefreshToken $token, bool $flush = false): void
    {
        $this->getEntityManager()->persist($token);
        if ($flush) $this->getEntityManager()->flush();
    }
}