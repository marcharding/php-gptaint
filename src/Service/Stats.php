<?php

namespace App\Service;

use App\Entity\AnalysisResult;
use Doctrine\ORM\EntityManagerInterface;

class Stats
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getStatistics(array $issues): array
    {
        // Get all analyzers in one query
        $analyzers = $this->entityManager->getConnection()
            ->executeQuery('SELECT DISTINCT analyzer FROM analysis_result ORDER BY analyzer DESC')
            ->fetchFirstColumn();

        // Initialize statistics arrays
        $statistics = $this->initializeStatistics($analyzers);
        $statisticsOverTime = [];

        // Fetch all relevant analysis results in bulk
        $allResults = $this->fetchAllAnalysisResults($issues);

        foreach ($issues as $issue) {
            $issueId = $issue->getId();
            $confirmedState = $issue->getConfirmedState();

            foreach ($analyzers as $analyzer) {
                $results = $allResults[$issueId][$analyzer] ?? [];

                if (empty($results)) {
                    continue;
                }

                $this->processAnalyzerResults(
                    $statistics,
                    $analyzer,
                    $confirmedState,
                    $results
                );

                $statisticsOverTime["{$analyzer}"][] = $this->calculateStatistics($statistics[$analyzer], $analyzer);
            }
        }

        // Clean up and finalize statistics
        $statistics = $this->finalizeStatistics($statistics);

        return [
            'statistics' => $statistics,
            'statisticsOverTime' => $statisticsOverTime
        ];
    }

    private function fetchAllAnalysisResults(array $issues): array
    {
        $issueIds = array_map(fn($issue) => $issue->getId(), $issues);

        $qb = $this->entityManager->createQueryBuilder();
        $results = $qb->select('ar')
            ->from(AnalysisResult::class, 'ar')
            ->where($qb->expr()->in('ar.issue', ':issueIds'))
            ->orderBy('ar.id', 'DESC')  // Ensure results are ordered by creation date
            ->setParameter('issueIds', $issueIds)
            ->getQuery()
            ->getResult();

        // Organize results by issue and analyzer
        $organized = [];
        foreach ($results as $result) {
            $issueId = $result->getIssue()->getId();
            $analyzer = $result->getAnalyzer();
            $organized[$issueId][$analyzer][] = $result;
        }

        return $organized;
    }

    private function initializeStatistics(array $analyzers): array
    {
        $statistics = [];
        foreach ($analyzers as $analyzer) {
            $statistics[$analyzer] = [
                'truePositives' => 0,
                'trueNegatives' => 0,
                'falsePositives' => 0,
                'falseNegatives' => 0,
                'time' => 0,
                'promptTokens' => 0,
                'completionTokens' => 0,
            ];

            // Initialize without feedback version
            if (!in_array($analyzer, ['psalm', 'snyk', 'phan'])) {
                $statistics["{$analyzer}_os"] = $statistics[$analyzer];
            }
        }
        return $statistics;
    }

    private function processAnalyzerResults(array &$statistics, string $analyzer, int $confirmedState, array $results): void
    {
        $lastResult = $this->findFirstFeedbackResult($results); // Get the most recent result (assuming ordered by createdAt DESC)

        if (in_array($analyzer, ['psalm', 'snyk', 'phan'])) {
            $statistics[$analyzer] = $this->getConfusionTable(
                $statistics[$analyzer],
                $confirmedState,
                $lastResult->getResultState()
            );
            $statistics[$analyzer]['time'] += $lastResult->getTime();
        } else {
            // Process GPT results
            $this->processGptResults($statistics, $analyzer, $confirmedState, $results);
        }
    }

    private function processGptResults(array &$statistics, string $analyzer, int $confirmedState, array $results): void
    {
        $lastResult = $this->findFirstFeedbackResult($results);
        $lastFeedbackResult = $this->findLastFeedbackResult($results);

        if ($lastFeedbackResult) {
            $statistics[$analyzer] = $this->getConfusionTable(
                $statistics[$analyzer],
                $confirmedState,
                $lastFeedbackResult->isExploitExampleSuccessful()
            );
        }

        // Process without feedback statistics
        $woFeedbackKey = "{$analyzer}_os";
        if ($lastResult) {
            $statistics[$woFeedbackKey] = $this->getConfusionTable(
                $statistics[$woFeedbackKey],
                $confirmedState,
                $lastResult->isExploitExampleSuccessful()
            );

            // Accumulate metrics
            $this->accumulateMetrics($statistics[$woFeedbackKey], $lastResult);
        }

        // Accumulate total metrics
        foreach ($results as $result) {
            $this->accumulateMetrics($statistics[$analyzer], $result);
        }
    }

    private function accumulateMetrics(array &$statistics, $result): void
    {
        $statistics['time'] += $result->getTime();
        $statistics['promptTokens'] += $result->getPromptTokens();
        $statistics['completionTokens'] += $result->getCompletionTokens();
    }

    private function findLastFeedbackResult(array $results)
    {
        foreach ($results as $result) {
            // Assuming feedback results are identified by having exploit example results
            if ($result->isExploitExampleSuccessful() !== null) {
                return $result;
            }
        }
        return null;
    }

    private function findFirstFeedbackResult(array $results)
    {
        foreach ($results as $result) {
            // Assuming feedback results are identified by having exploit example results
            if ($result->getParentResult() === null) {
                return $result;
            }
        }
        return null;
    }

    private function finalizeStatistics(array $statistics): array
    {
        foreach ($statistics as $analyzer => $statistic) {
            $statistics[$analyzer] = $this->calculateStatistics($statistic, $analyzer);
            if (!isset($statistics[$analyzer]['time']) || $statistics[$analyzer]['time'] === 0) {
                unset($statistics[$analyzer]);
            }
        }
        return $statistics;
    }

    public function calculateStatistics($results, $analyzer): array
    {
        $count = $results['truePositives'] + $results['trueNegatives'] + $results['falsePositives'] + $results['falseNegatives'];
        $results['sum'] = $count;
        $results['count'] = $count;
        $results['recall'] = ($results['truePositives'] + $results['falseNegatives']) != 0 ? $results['truePositives'] / ($results['truePositives'] + $results['falseNegatives']) : 0;
        $results['precision'] = ($results['truePositives'] + $results['falsePositives']) != 0 ? $results['truePositives'] / ($results['truePositives'] + $results['falsePositives']) : 0;
        $results['accuracy'] = $count != 0 ? ($results['truePositives'] + $results['trueNegatives']) / $count : 0;
        $results['specificity'] = ($results['trueNegatives'] + $results['falsePositives']) != 0 ? $results['trueNegatives'] / ($results['trueNegatives'] + $results['falsePositives']) : 0;
        $results['far'] = ($results['falsePositives'] + $results['trueNegatives']) == 0 ? 0 : $results['falsePositives'] / ($results['falsePositives'] + $results['trueNegatives']);
        $results['gscore'] = ($results['recall'] + 1 - $results['far']) == 0 ? 0 : (2 * $results['recall'] * (1 - $results['far'])) / ($results['recall'] + 1 - $results['far']);
        $results['f1'] = ($results['truePositives'] + $results['falsePositives'] + $results['falseNegatives']) != 0 ? 2 * $results['truePositives'] / (2 * $results['truePositives'] + $results['falsePositives'] + $results['falseNegatives']) : 0;

        // TODO: make not hardcoded
        $costs = match (true) {
            str_contains($analyzer, 'gpt-4o-mini') => ['promptCost' => 0.150, 'completionCost' => 0.600],
            str_contains($analyzer, 'gpt-4o') => ['promptCost' => 2.50, 'completionCost' => 10.00],
            str_contains($analyzer, 'gpt-3.5') => ['promptCost' => 0.50, 'completionCost' => 1.50],
            default => ['promptCost' => 0, 'completionCost' => 1],
        };
        $results['costs'] = ($results['promptTokens'] / 1000000 * $costs['promptCost']) + ($results['completionTokens'] / 1000000 * $costs['completionCost']);

        $results = array_map(function ($result) { return round($result, 2); }, $results);

        $results['time'] = $results['time'] / 1000;
        $totalMinutes = floor($results['time'] / 60);
        $remainingSeconds = round($results['time']) % 60;
        $results['time'] = "{$totalMinutes}m {$remainingSeconds}s";

        return $results;
    }

    public function getConfusionTable($table, $confirmedState, $state)
    {
        if ($confirmedState === 1) {
            if ($state) {
                $table['truePositives']++;
            } else {
                $table['falseNegatives']++;
            }
        }

        if ($confirmedState === 0) {
            if (!$state) {
                $table['trueNegatives']++;
            } else {
                $table['falsePositives']++;
            }
        }

        return $table;
    }
}
