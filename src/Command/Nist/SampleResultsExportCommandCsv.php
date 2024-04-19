<?php

namespace App\Command\Nist;

use App\Entity\GptResult;
use App\Entity\Issue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:sample:results:export:csv',
    description: 'Export the results as a csv (to plot graphs)',
)]
class SampleResultsExportCommandCsv extends Command
{
    private $projectDir;
    private EntityManagerInterface $entityManager;

    protected function configure(): void
    {
        $this
            ->addArgument('outputFile', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.')
            ->addOption('print-to-stdout', null, InputOption::VALUE_NONE)
            ->addOption('columns', null, InputOption::VALUE_OPTIONAL, 'Model to use (if none is given the default model from the configuration is used).',
                ['truePositives', 'trueNegatives', 'falsePositives', 'falseNegatives', 'time', 'sum', 'count', 'sensitivity', 'precision', 'accuracy', 'specificity', 'f1']);
    }

    public function __construct(string $projectDir, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issues = $this->entityManager->getRepository(Issue::class)->findAll();

        $gptResultRepository = $this->entityManager->getRepository(GptResult::class);

        if (!$input->getArgument('outputFile')) {
            $outputFile = $this->projectDir.'/results_'.time().'.csv';
        } else {
            $outputFile = $this->projectDir.'/'.$input->getArgument('outputFile');
        }

        $columns = $input->getOption('columns');
        if (!is_array($columns)) {
            $columns = explode(',', $columns);
        }

        $analyzers = [
            'psalm',
            'snyk',
            'phan',
        ];

        $modelValues = $this->entityManager->getConnection()->executeQuery('SELECT DISTINCT gpt_version FROM gpt_result')->fetchAllAssociative();
        $modelValues = array_column($modelValues, 'gpt_version');
        $analyzers = array_merge($analyzers, $modelValues);

        // add llm methods

        $statistics = [];

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
            }
        }

        foreach ($statistics as $analyzer => $statistic) {
            $statistics[$analyzer] = $this->calculateStatistics($statistic);
            // remove static analyzer which were not run
            if ($statistics[$analyzer]['time'] === 0) {
                unset($statistics[$analyzer]);
            }
        }

        $printToStdout = $input->getOption('print-to-stdout');

        if ($printToStdout) {
            $file = fopen('php://stdout', 'w');
        } else {
            $file = fopen($outputFile, 'w');
        }

        // Header row
        $row = reset($statistics);
        foreach ($row as $key => $value) {
            if (array_search($key, $columns) === false) {
                unset($row[$key]);
            }
        }
        $row = array_keys($row);
        array_unshift($row, 'Method');
        fputcsv($file, $row);

        // Body rows
        foreach ($statistics as $type => $row) {
            foreach ($row as $key => $value) {
                if (array_search($key, $columns) === false) {
                    unset($row[$key]);
                }
            }
            array_unshift($row, $type);
            fputcsv($file, array_values($row));
        }

        fclose($file);

        return Command::SUCCESS;
    }

    private function getConfusionTable($table, $confirmedState, $state)
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

    private function calculateStatistics($results)
    {
        $count = $results['truePositives'] + $results['trueNegatives'] + $results['falsePositives'] + $results['falseNegatives'];

        $results['sum'] = $count;
        $results['count'] = $count;
        $results['sensitivity'] = $results['truePositives'] != 0 ? $results['truePositives'] / 25 : 0;
        $results['precision'] = ($results['truePositives'] + $results['falsePositives']) != 0 ? $results['truePositives'] / ($results['truePositives'] + $results['falsePositives']) : 0;
        $results['accuracy'] = $count != 0 ? ($results['truePositives'] + $results['trueNegatives']) / $count : 0;
        $results['specificity'] = ($results['trueNegatives'] + $results['falsePositives']) != 0 ? $results['trueNegatives'] / ($results['trueNegatives'] + $results['falsePositives']) : 0;
        $results['f1'] = ($results['truePositives'] + $results['falsePositives'] + $results['falseNegatives']) != 0 ? 2 * $results['truePositives'] / (2 * $results['truePositives'] + $results['falsePositives'] + $results['falseNegatives']) : 0;

        return $results;
    }
}
