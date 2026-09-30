<?php

namespace App\Repository;

use App\Entity\ProductImage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProductImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductImage::class);
    }

    public function findByProduct(int $productId): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.product = :productId')
            ->setParameter('productId', $productId)
            ->orderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Repositionne toutes les images d'un produit après suppression ou réordonnement
    public function reorderForProduct(int $productId): void
    {
        $images = $this->findByProduct($productId);
        $em = $this->getEntityManager();

        foreach ($images as $i => $image) {
            $image->setPosition($i);
            $em->persist($image);
        }
        $em->flush();
    }

    public function save(ProductImage $image, bool $flush = false): void
    {
        $this->getEntityManager()->persist($image);
        if ($flush) $this->getEntityManager()->flush();
    }

    public function remove(ProductImage $image, bool $flush = false): void
    {
        $this->getEntityManager()->remove($image);
        if ($flush) $this->getEntityManager()->flush();
    }
}