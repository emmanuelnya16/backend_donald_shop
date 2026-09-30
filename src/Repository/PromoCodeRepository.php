<?php

namespace App\Repository;

use App\Entity\PromoCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PromoCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCode::class);
    }

    public function findByCode(string $code): ?PromoCode
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    public function codeExists(string $code): bool
    {
        return $this->findByCode($code) !== null;
    }

    public function findAllActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(PromoCode $promoCode, bool $flush = false): void
    {
        $this->getEntityManager()->persist($promoCode);
        if ($flush) $this->getEntityManager()->flush();
    }
}