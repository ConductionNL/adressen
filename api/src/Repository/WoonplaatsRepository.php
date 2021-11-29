<?php

namespace App\Repository;

use App\Entity\Woonplaats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Woonplaats|null find($id, $lockMode = null, $lockVersion = null)
 * @method Woonplaats|null findOneBy(array $criteria, array $orderBy = null)
 * @method Woonplaats[]    findAll()
 * @method Woonplaats[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WoonplaatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Woonplaats::class);
    }

    // /**
    //  * @return Woonplaats[] Returns an array of Woonplaats objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Woonplaats
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
