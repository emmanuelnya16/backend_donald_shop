<?php

namespace App\Repository;

use App\Entity\Admin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    public function findByEmail(string $email): ?Admin
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findActiveByEmail(string $email): ?Admin
    {
        return $this->createQueryBuilder('a')
            ->where('a.email = :email')
            ->andWhere('a.isActive = true')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Admin $admin, bool $flush = false): void
    {
        $this->getEntityManager()->persist($admin);
        if ($flush) $this->getEntityManager()->flush();
    }
}