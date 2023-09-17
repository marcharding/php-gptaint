<?php

namespace App\Repository;

use App\Entity\Issue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Issue>
 *
 * @method Issue|null find($id, $lockMode = null, $lockVersion = null)
 * @method Issue|null findOneBy(array $criteria, array $orderBy = null)
 * @method Issue[]    findAll()
 * @method Issue[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Issue::class);
    }

    public function save(Issue $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Issue $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

//    /**
//     * @return Issue[] Returns an array of Issue objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('i')
//            ->andWhere('i.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('i.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Issue
//    {
//        return $this->createQueryBuilder('i')
//            ->andWhere('i.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }

    public function findDistinctIssueTypes(): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.type, COUNT(i.id) as typeCount')
            ->groupBy('i.type')
            ->orderBy('typeCount', 'DESC')
            ->getQuery()

            ->getResult();
    }

    public function findByIssueType(string $type): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getResult();
    }

    public function findAllWithGptResult(): array
    {
       return $this->createQueryBuilder('i')
            ->innerJoin('i.gptResults', 'g')
            ->groupBy('i')
            ->orderBy('AVG(g.exploitProbability)', 'DESC')
            ->getQuery()
           ->getResult();
    }

}
