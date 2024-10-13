<?php

namespace App\Repository;

use App\Entity\Sample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sample>
 *
 * @method Sample|null find($id, $lockMode = null, $lockVersion = null)
 * @method Sample|null findOneBy(array $criteria, array $orderBy = null)
 * @method Sample[]    findAll()
 * @method Sample[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sample::class);
    }

    public function save(Sample $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Sample $entity, bool $flush = false): void
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

    public function findByAnalyzerResults($analyzer, $type = null): array
    {
        $results = [];

        // Retrieve all initial issues or items using the supplied analyzer
        $initialItems = $this->createQueryBuilder('i')
            ->innerJoin('i.gptResults', 'g')
            ->andWhere('g.analyzer = :analyzer')
            ->setParameter('analyzer', $analyzer)
            ->getQuery()
            ->getResult();

        foreach ($initialItems as $item) {
            // Find the last GPT result associated with the item
            $gptResult = $this->findLastGptResultByIssue($item);

            if (!$gptResult) {
                continue;
            }

            $meetsCriteria = false;
            if ($type === 'tp' && $item->getConfirmedState() == 1 && $gptResult->getResultState() == 1) {
                $meetsCriteria = true;
            } elseif ($type === 'fp' && $item->getConfirmedState() == 0 && $gptResult->getResultState() == 1) {
                $meetsCriteria = true;
            } elseif ($type === 'tn' && $item->getConfirmedState() == 0 && $gptResult->getResultState() == 0) {
                $meetsCriteria = true;
            } elseif ($type === 'fn' && $item->getConfirmedState() == 1 && $gptResult->getResultState() == 0) {
                $meetsCriteria = true;
            } elseif ($type === null) {
                // If no specific type is given, include all results
                $meetsCriteria = true;
            }

            if ($meetsCriteria) {
                $results[] = $item;
            }
        }

        return $results;
    }
}
