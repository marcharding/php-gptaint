<?php

namespace App\Repository;

use App\Entity\AnalysisResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AnalysisResult>
 *
 * @method AnalysisResult|null find($id, $lockMode = null, $lockVersion = null)
 * @method AnalysisResult|null findOneBy(array $criteria, array $orderBy = null)
 * @method AnalysisResult[]    findAll()
 * @method AnalysisResult[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class GptResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnalysisResult::class);
    }

    public function save(AnalysisResult $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AnalysisResult $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findLastGptResultByIssue($issue, $model = 'gpt-3.5-turbo%-0613'): AnalysisResult|null
    {
        return $this->createQueryBuilder('g')
            ->where('g.parentResult IS NULL')
            ->andWhere('g.issue = :issue')
            ->andWhere('g.analyzer LIKE :model')
            ->setParameter('issue', $issue)
            ->setParameter('model', $model)
            ->orderBy('g.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllGptResultByIssue($issue, $model = 'gpt-3.5-turbo%-0613'): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.parentResult IS NULL')
            ->andWhere('g.issue = :issue')
            ->andWhere('g.analyzer LIKE :model')
            ->setParameter('issue', $issue)
            ->setParameter('model', $model)
            ->orderBy('g.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findLastGptResultByParentGptResult($gptResult): AnalysisResult|null
    {
        return $this->createQueryBuilder('g')
            ->where('g.parentResult = :gptResult')
            ->setParameter('gptResult', $gptResult)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLastFeedbackGptResultByIssue($issue, $model = 'gpt-3.5-turbo%-0613'): AnalysisResult|null
    {
        $gptResult = $lastGptResult = $this->findLastGptResultByIssue($issue, $model);
        while ($gptResult) {
            $lastGptResult = $gptResult;
            $gptResult = $this->findLastGptResultByParentGptResult($gptResult);
        }

        return $lastGptResult;
    }

    public function findAllFeedbackGptResultByIssue($issue, $model = 'gpt-3.5-turbo%-0613'): array
    {
        $allGptResults = [];
        $gptResults = $lastGptResult = $this->findAllGptResultByIssue($issue, $model);
        foreach ($gptResults as $gptResult) {
            while ($gptResult) {
                $lastGptResult = $gptResult;
                $gptResult = $this->findLastGptResultByParentGptResult($gptResult);
            }
            $allGptResults[] = $lastGptResult;
        }

        return $allGptResults;
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

    public function getPromptTokenSum($issue, $model = 'gpt-3.5-turbo%-0613'): int
    {
        $gptResult = $this->findLastGptResultByIssue($issue, $model);
        $tokens = 0;
        while ($gptResult) {
            $tokens += $gptResult->getPromptTokens();
            $gptResult = $this->findLastGptResultByParentGptResult($gptResult);
        }

        return $tokens;
    }

    public function getCompletionTokenSum($issue, $model = 'gpt-3.5-turbo%-0613'): int
    {
        $gptResult = $this->findLastGptResultByIssue($issue, $model);
        $tokens = 0;
        while ($gptResult) {
            $tokens += $gptResult->getCompletionTokens();
            $gptResult = $this->findLastGptResultByParentGptResult($gptResult);
        }

        return $tokens;
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
