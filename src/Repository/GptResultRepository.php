<?php

namespace App\Repository;

use App\Entity\GptResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GptResult>
 *
 * @method GptResult|null find($id, $lockMode = null, $lockVersion = null)
 * @method GptResult|null findOneBy(array $criteria, array $orderBy = null)
 * @method GptResult[]    findAll()
 * @method GptResult[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class GptResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GptResult::class);
    }

    public function save(GptResult $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(GptResult $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findLastGptResultByIssue($issue, $model = 'gpt-3.5-turbo%-0613'): GptResult|null
    {
        return $this->createQueryBuilder('g')
            ->where('g.gptResultParent IS NULL')
            ->andWhere('g.issue = :issue')
            ->andWhere('g.gptVersion LIKE :model')
            ->setParameter('issue', $issue)
            ->setParameter('model', $model)
            ->orderBy('g.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLastGptResultByParentGptResult($gptResult): GptResult|null
    {
        return $this->createQueryBuilder('g')
            ->where('g.gptResultParent = :gptResult')
            ->setParameter('gptResult', $gptResult)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLastFeedbackGptResultByIssue($issue, $model = 'gpt-3.5-turbo%-0613'): GptResult|null
    {
        $gptResult = $lastGptResult = $this->findLastGptResultByIssue($issue, $model);
        while ($gptResult) {
            $lastGptResult = $gptResult;
            $gptResult = $this->findLastGptResultByParentGptResult($gptResult);
        }

        return $lastGptResult;
    }

    public function getTimeSum($issue, $model = 'gpt-3.5-turbo%-0613'): int
    {
        $gptResult = $this->findLastGptResultByIssue($issue, $model);
        $time = 0;
        while ($gptResult) {
            $time += $gptResult->getTime();
            $gptResult = $this->findLastGptResultByParentGptResult($gptResult);
        }

        return $time;
    }

    //    /**
    //     * @return GptResult[] Returns an array of GptResult objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('g')
    //            ->andWhere('g.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('g.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?GptResult
    //    {
    //        return $this->createQueryBuilder('g')
    //            ->andWhere('g.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
