<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByPhone(string $phone): ?User
    {
        return $this->findOneBy(['phone' => $phone]);
    }

    public function phoneExists(string $phone): bool
    {
        return $this->findByPhone($phone) !== null;
    }

    public function findActiveByPhone(string $phone): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.phone = :phone')
            ->andWhere('u.status = :status')
            ->setParameter('phone', $phone)
            ->setParameter('status', User::STATUS_ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(User $user, bool $flush = false): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) $this->getEntityManager()->flush();
    }

    /**
     * @return User[]
     */
    public function findAllForAdmin(array $filters): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC');

        if (!empty($filters['search'])) {
            $qb->andWhere('u.firstName LIKE :search OR u.lastName LIKE :search OR u.phone LIKE :search')
               ->setParameter('search', '%' . trim($filters['search']) . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('u.status = :status')
               ->setParameter('status', trim($filters['status']));
        }

        if (!empty($filters['city'])) {
            $qb->andWhere('u.city LIKE :city')
               ->setParameter('city', '%' . trim($filters['city']) . '%');
        }

        return $qb->getQuery()->getResult();
    }
}