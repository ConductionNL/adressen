<?php

namespace App\Repository;

use App\Entity\OpenbareRuimte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method OpenbareRuimte|null find($id, $lockMode = null, $lockVersion = null)
 * @method OpenbareRuimte|null findOneBy(array $criteria, array $orderBy = null)
 * @method OpenbareRuimte[]    findAll()
 * @method OpenbareRuimte[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class OpenbareRuimteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpenbareRuimte::class);
    }

    // /**
    //  * @return OpenbareRuimte[] Returns an array of OpenbareRuimte objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?OpenbareRuimte
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
