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
        $modelValues = $this->entityManager->getConnection()
            ->executeQuery('SELECT DISTINCT analyzer FROM analysis_result')
            ->fetchAllAssociative();

        $gptResultRepository = $this->entityManager->getRepository(AnalysisResult::class);
        $analyzers = array_column($modelValues, 'analyzer');

        $statistics = [];
        $statisticsOverTime = [];

        if (false) {
            foreach ($issues as $issue) {
                // TODO: Extract to extra command, currently disabled

                $tests = $gptResultRepository->findAllFeedbackGptResultByIssue($issue, 'gpt-3.5-turbo-0125');

                $tmp = [];
                $tmp[$issue->getName()]['state'] = $issue->getConfirmedState();
                $tmp[$issue->getName()]['isExploitExampleSuccessful'] = [];
                $tmp[$issue->getName()]['gptResult'] = [];

                foreach ($tests as $item) {
                    $tmp[$issue->getName()]['isExploitExampleSuccessful'][] = $item->isExploitExampleSuccessful();
                    $tmp[$issue->getName()]['gptResult'][] = $item;
                }

                // TODO: Refactor. This check if the results were consistent between multiple runs
                foreach ($tmp as $item) {
                    if (count(array_unique($item['isExploitExampleSuccessful'])) !== 1) {
                        // dump($issue->getName());
                        foreach ($item['gptResult'] as $gptResult) {
                            // dump($item['isExploitExampleSuccessful']);
                        }
                    }
                }
            }
        }

        foreach ($issues as $issue) {
            foreach ($analyzers as $analyzer) {
                if (!isset($statistics[$analyzer])) {
                    $statistics[$analyzer] = [
                        'truePositives' => 0,
                        'trueNegatives' => 0,
                        'falsePositives' => 0,
                        'falseNegatives' => 0,
                        'time' => 0,
                    ];
                }

                switch ($analyzer) {
                    case 'psalm':
                    case 'snyk':
                    case 'phan':
                        $gptResultWithoutFeedback = $gptResultRepository->findLastGptResultByIssue($issue, $analyzer);
                        $statistics[$analyzer] = $this->getConfusionTable($statistics[$analyzer], $issue->getConfirmedState(), $gptResultWithoutFeedback->getResultState());
                        $statistics[$analyzer]['time'] += $gptResultWithoutFeedback->getTime();
                        break;
                    default:
                        $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, $analyzer);
                        if ($gptResult) {
                            $statistics[$analyzer] = $this->getConfusionTable($statistics[$analyzer], $issue->getConfirmedState(), $gptResult->isExploitExampleSuccessful());
                        }

                        $gptResultWithoutFeedback = $gptResultRepository->findLastGptResultByIssue($issue, $analyzer);
                        if ($gptResultWithoutFeedback) {
                            $analyzerWithoutFeedback = "{$analyzer}_wo_feedback";
                            if (!isset($statistics[$analyzerWithoutFeedback])) {
                                $statistics[$analyzerWithoutFeedback] = [
                                    'truePositives' => 0,
                                    'trueNegatives' => 0,
                                    'falsePositives' => 0,
                                    'falseNegatives' => 0,
                                    'time' => 0,
                                ];
                            }
                            $statistics[$analyzerWithoutFeedback] = $this->getConfusionTable($statistics[$analyzerWithoutFeedback], $issue->getConfirmedState(), $gptResultWithoutFeedback->isExploitExampleSuccessful());
                            $statistics[$analyzerWithoutFeedback]['time'] += $gptResultRepository->getTimeSum($issue, $analyzer); // Assuming same method for time sum
                        }

                        $statistics[$analyzer]['time'] += $gptResultRepository->getTimeSum($issue, $analyzer);

                        break;
                }

                $statisticsOverTime["{$analyzer}"][] = $this->calculateStatistics($statistics[$analyzer]);
            }
        }

        foreach ($statistics as $analyzer => $statistic) {
            $statistics[$analyzer] = $this->calculateStatistics($statistic);
            // remove static analyzer which were not run
            if ($statistics[$analyzer]['time'] === 0) {
                unset($statistics[$analyzer]);
            }
        }

        return ['statistics' => $statistics, 'statisticsOverTime' => $statisticsOverTime];
    }

    public function calculateStatistics($results)
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

        $results = array_map(function ($result) { return round($result, 2); }, $results);

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
