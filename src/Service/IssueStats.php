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

            // one shot
            foreach ($analyzers as $analyzer) {
                $analyzerStats = [
                    'TP' => 0,
                    'TN' => 0,
                    'FP' => 0,
                    'FN' => 0,
                    'triesCount' => 0,
                    'differentExploits' => 0,
                ];

                $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, $analyzer);
                if ($gptResult) {
                    $analyzerStats = $this->getConfusionTable(
                        $analyzerStats,
                        $issue->getConfirmedState(),
                        $gptResult->getResultState()
                    );
                    $analyzerStats['triesCount'] = $gptResult->getParentCount();
                    $differentExploits = $this->entityManager->getConnection()
                        ->executeQuery("
SELECT analysis_result.exploit_example
FROM analysis_result
LEFT JOIN sample ON analysis_result.issue_id = sample.id
WHERE analyzer = '{$analyzer}'
AND analysis_result.issue_id = {$gptResult->getIssue()->getId()}
")->fetchFirstColumn();
                    $differentExploits = array_unique($differentExploits);

                    $analyzerStats['differentExploits'] = count($differentExploits);
                    $issueStatistics['analyzers'][$analyzer] = $analyzerStats;
                } else {
                    $issueStatistics['analyzers'][$analyzer] = $analyzerStatsDummy;
                }
            }

            // with feedback
            foreach ($analyzers as $analyzer) {
                $analyzerStats = [
                    'TP' => 0,
                    'TN' => 0,
                    'FP' => 0,
                    'FN' => 0,
                    'triesCount' => 0,
                    'differentExploits' => 0,
                ];

                $gptResultWithoutFeedback = $gptResultRepository->findLastGptResultByIssue($issue, $analyzer);
                if ($gptResultWithoutFeedback) {
                    $analyzerWithoutFeedback = "{$analyzer}_wo_feedback";
                    $analyzerStats = $this->getConfusionTable(
                        $analyzerStats,
                        $issue->getConfirmedState(),
                        $gptResult->getResultState()
                    );
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
