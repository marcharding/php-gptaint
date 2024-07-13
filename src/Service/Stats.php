<?php

namespace App\Service;

use App\Entity\GptResult;
use Doctrine\ORM\EntityManagerInterface;

class Stats
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function getStatistics(array $issues): array
    {
        $analyzers = [
            'psalm',
            'snyk',
            'phan',
        ];

        $modelValues = $this->entityManager->getConnection()->executeQuery('SELECT DISTINCT gpt_version FROM gpt_result')->fetchAllAssociative();
        $gptResultRepository = $this->entityManager->getRepository(GptResult::class);
        $modelValues = array_column($modelValues, 'gpt_version');
        $analyzers = array_merge($analyzers, $modelValues);

        // add llm methods

        $statistics = [];
        $statisticsOverTime = [];
        foreach ($issues as $key => $issue) {
            // TODO: Extract to extra command, currently disabled
            if (false) {
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
                        $statistics[$analyzer] = $this->getConfusionTable($statistics[$analyzer], $issue->getConfirmedState(), $issue->getPsalmState());
                        $statistics[$analyzer]['time'] += $issue->getPsalmTime();
                        break;
                    case 'snyk':
                        $statistics[$analyzer] = $this->getConfusionTable($statistics[$analyzer], $issue->getConfirmedState(), $issue->getSnykState());
                        $statistics[$analyzer]['time'] += $issue->getSnykTime();
                        break;
                    case 'phan':
                        $statistics[$analyzer] = $this->getConfusionTable($statistics[$analyzer], $issue->getConfirmedState(), $issue->getPhanState());
                        $statistics[$analyzer]['time'] += $issue->getPhanTime();
                        break;
                    default:
                        if (in_array($analyzer, $modelValues)) {
                            $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, $analyzer);
                            if ($gptResult) {
                                $statistics[$analyzer] = $this->getConfusionTable($statistics[$analyzer], $issue->getConfirmedState(), $gptResult->isExploitExampleSuccessful());
                            }
                            $statistics[$analyzer]['time'] += $gptResultRepository->getTimeSum($issue, $analyzer);
                            break;
                        }
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

        // TODO: Extract to command
        $maxLengths = array_map('count', $statisticsOverTime);
        $maxLength = max($maxLengths);

        $statsAnalyzers = [
            'psalm',
            'snyk',
            'phan',
            'gpt-4o (randomized)',
            'llama-3-8b (randomized)',
            'gpt-3.5-turbo-0125 (randomized)',
        ];

        $metrics = ['f1', 'far', 'gscore'];
        foreach ($metrics as $metric) {
            file_put_contents(__DIR__."/../../results_over_time_{$metric}.csv", 'count;'.implode(';', $statsAnalyzers).PHP_EOL);
            for ($i = 1; $i < $maxLength; $i++) {
                $row = [];
                $row[] = $i;
                foreach ($statsAnalyzers as $analyzer) {
                    if (isset($statisticsOverTime[$analyzer][$i])) {
                        $row[] = $statisticsOverTime[$analyzer][$i][$metric];
                    } else {
                        $row[] = 0;
                    }
                }
                file_put_contents(__DIR__."/../../results_over_time_{$metric}.csv", implode(';', $row).PHP_EOL, FILE_APPEND);
            }
        }

        $metrics = ['f1', 'far', 'gscore', 'recall'];
        file_put_contents(__DIR__.'/../../results_total.csv', 'analyzer;'.implode(';', $metrics).PHP_EOL);
        foreach ($statsAnalyzers as $analyzer) {
            $row = [];
            $row[] = $analyzer;
            foreach ($metrics as $metric) {
                if (isset($statistics[$analyzer][$metric])) {
                    if ($metric === 'far') {
                        $row[] = 1 - $statistics[$analyzer][$metric];
                    } else {
                        $row[] = $statistics[$analyzer][$metric];
                    }
                } else {
                    $row[] = 0;
                }
            }
            file_put_contents(__DIR__.'/../../results_total_metrics.csv', implode(';', $row).PHP_EOL, FILE_APPEND);
        }

        $metrics = ['f1', 'far', 'gscore', 'recall'];
        file_put_contents(__DIR__.'/../../results_total.csv', 'analyzer;'.implode(';', $metrics).PHP_EOL);
        foreach ($statsAnalyzers as $analyzer) {
            $row = [];
            $row[] = $analyzer;
            foreach ($metrics as $metric) {
                if (isset($statistics[$analyzer][$metric])) {
                    if ($metric === 'far') {
                        $row[] = 1 - $statistics[$analyzer][$metric];
                    } else {
                        $row[] = $statistics[$analyzer][$metric];
                    }
                } else {
                    $row[] = 0;
                }
            }
            file_put_contents(__DIR__.'/../../results_total_metrics_".csv', implode(';', $row).PHP_EOL, FILE_APPEND);
        }

        return $statistics;
    }

    public function calculateStatistics($results)
    {
        $count = $results['truePositives'] + $results['trueNegatives'] + $results['falsePositives'] + $results['falseNegatives'];

        $results['sum'] = $count;

        $results['count'] = $count;

        $results['recall'] = ($results['truePositives'] + $results['falseNegatives']) != 0 ? $results['truePositives'] / ($results['truePositives'] + $results['falseNegatives']) : 0;

        $results['sensitivity'] = $results['truePositives'] != 0 ? $results['truePositives'] / $count : 0;

        $results['precision'] = ($results['truePositives'] + $results['falsePositives']) != 0 ? $results['truePositives'] / ($results['truePositives'] + $results['falsePositives']) : 0;

        $results['accuracy'] = $count != 0 ? ($results['truePositives'] + $results['trueNegatives']) / $count : 0;

        $results['specificity'] = ($results['trueNegatives'] + $results['falsePositives']) != 0 ? $results['trueNegatives'] / ($results['trueNegatives'] + $results['falsePositives']) : 0;

        $results['far'] = ($results['falsePositives'] + $results['trueNegatives']) == 0 ? 0 : $results['falsePositives'] / ($results['falsePositives'] + $results['trueNegatives']);

        $results['gscore'] = (2 * $results['recall'] * (1 - $results['far'])) / ($results['recall'] + 1 - $results['far']);

        $results['f1'] = ($results['truePositives'] + $results['falsePositives'] + $results['falseNegatives']) != 0 ? 2 * $results['truePositives'] / (2 * $results['truePositives'] + $results['falsePositives'] + $results['falseNegatives']) : 0;

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
