<?php

namespace App\Service;

use App\Entity\AnalysisResult;
use Doctrine\ORM\EntityManagerInterface;

class IssueStats
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getStatistics(array $issues): array
    {
        // Fetch distinct analyzers from analysis_result
        $modelValues = $this->entityManager->getConnection()
            ->executeQuery('SELECT DISTINCT analyzer FROM analysis_result')
            ->fetchAllAssociative();

        $gptResultRepository = $this->entityManager->getRepository(AnalysisResult::class);
        $analyzers = array_column($modelValues, 'analyzer');
        $analyzerStatsDummy = [
            'TP' => '-',
            'TN' => '-',
            'FP' => '-',
            'FN' => '-',
            'triesCount' => '-',
        ];

        $statistics = [];

        foreach ($issues as $issue) {
            $issueStatistics = [
                'analyzers' => [],
            ];

            foreach ($analyzers as $analyzer) {
                $analyzerStats = [
                    'TP' => 0,
                    'TN' => 0,
                    'FP' => 0,
                    'FN' => 0,
                    'triesCount' => 0,
                ];

                $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, $analyzer);
                if ($gptResult) {
                    $analyzerStats = $this->getConfusionTable(
                        $analyzerStats,
                        $issue->getConfirmedState(),
                        $gptResult->getResultState()
                    );
                    $analyzerStats['triesCount'] = $gptResult->getParentCount();
                    $issueStatistics['analyzers'][$analyzer] = $analyzerStats;
                } else {
                    $issueStatistics['analyzers'][$analyzer] = $analyzerStatsDummy;
                }

                $gptResultWithoutFeedback = $gptResultRepository->findLastGptResultByIssue($issue, $analyzer);
                if ($gptResultWithoutFeedback) {
                    $analyzerWithoutFeedback = "{$analyzer}_wo_feedback";
                    $analyzerStats = $this->getConfusionTable(
                        $analyzerStats,
                        $issue->getConfirmedState(),
                        $gptResult->getResultState()
                    );
                    $analyzerStats['triesCount'] = 0;
                    $issueStatistics['analyzers'][$analyzerWithoutFeedback] = $analyzerStats;
                } else {
                    $issueStatistics['analyzers'][$analyzer] = $analyzerStatsDummy;

                }

            }

            $statistics[$issue->getName()] = $issueStatistics;
        }

        return $statistics;
    }

    private function getConfusionTable(array $table, bool $confirmedState, bool $state): array
    {
        if ($confirmedState) {
            if ($state) {
                $table['TP']++;
            } else {
                $table['FN']++;
            }
        } else {
            if (!$state) {
                $table['TN']++;
            } else {
                $table['FP']++;
            }
        }

        return $table;
    }
}
