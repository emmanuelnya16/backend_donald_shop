<?php

namespace App\Repository;

use App\Entity\DeliveryAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DeliveryAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryAddress::class);
    }

    public function findByOrder(int $orderId): ?DeliveryAddress
    {
        return $this->createQueryBuilder('da')
            ->where('da.order = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(DeliveryAddress $address, bool $flush = false): void
    {
        $this->getEntityManager()->persist($address);
        if ($flush) $this->getEntityManager()->flush();
    }
}