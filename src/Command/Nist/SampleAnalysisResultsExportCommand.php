<?php

namespace App\Command\Nist;

use App\Repository\IssueRepository;
use App\Service\Stats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:analysis:results:export:csv',
    description: 'Generate statistics and write them to CSV files.'
)]
class SampleAnalysisResultsExportCommand extends Command
{
    private Stats $statsService;
    private IssueRepository $issueRepository;
    private string $projectDir;
    private array $statsAnalyzers = [];
    private EntityManagerInterface $entityManager;

    public function __construct(string $projectDir, Stats $statsService, IssueRepository $issueRepository, EntityManagerInterface $entityManager)
    {
        $this->projectDir = $projectDir;
        $this->statsService = $statsService;
        $this->issueRepository = $issueRepository;
        $this->entityManager = $entityManager;
        $modelValues = $this->entityManager->getConnection()
            ->executeQuery('SELECT DISTINCT analyzer FROM analysis_result')
            ->fetchAllAssociative();
        $modelValues = array_column($modelValues, 'analyzer');
        $modelValuesWoFeedback = array_map(function ($item) {
            return "{$item}_wo_feedback";
        }, $modelValues);
        $modelValues = array_merge($modelValues, $modelValuesWoFeedback);
        $this->statsAnalyzers = $modelValues;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('randomized', null, InputOption::VALUE_NONE, 'Only use the randomized versions')
            ->addOption('no-randomized', null, InputOption::VALUE_NONE, 'Only use the non-randomized versions')
            ->addOption('feedback', null, InputOption::VALUE_NONE, 'Only use the feedback versions')
            ->addOption('no-feedback', null, InputOption::VALUE_NONE, 'Only use the non-feedback versions')
            ->addOption('metrics', null, InputOption::VALUE_OPTIONAL, 'Only use these metrics')
            ->addOption('analyzer', null, InputOption::VALUE_OPTIONAL, 'Only use these analyzers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->filterAnalyzers($input);

        $issues = $this->issueRepository->findAll();
        $statistics = $this->statsService->getStatistics($issues);

        $this->generateResultsOverTimeCsv($statistics['statisticsOverTime'], $input->getOption('metrics'));
        $this->generateResultsTotalCsv($statistics['statistics'], $input->getOption('metrics'));

        $output->writeln('Statistics generated successfully.');

        return Command::SUCCESS;
    }

    private function filterAnalyzers(InputInterface $input): void
    {
        $randomized = $input->getOption('randomized');
        $noRandomized = $input->getOption('no-randomized');
        $feedback = $input->getOption('feedback');
        $noFeedback = $input->getOption('no-feedback');
        $onlyKeepTheseAnalyzers = $input->getOption('analyzer');

        if ($randomized) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '(randomized)') !== false);
        } elseif ($noRandomized) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '(randomized)') === false);
        }

        if ($feedback) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, 'wo_feedback') === false);
        } elseif ($noFeedback) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, 'wo_feedback') !== false);
        }

        $this->statsAnalyzers = array_values($this->statsAnalyzers);

        if ($onlyKeepTheseAnalyzers) {
            $onlyKeepTheseAnalyzers = explode(',', $onlyKeepTheseAnalyzers);
            $this->statsAnalyzers = array_intersect($this->statsAnalyzers, $onlyKeepTheseAnalyzers);
        }
    }

    private function generateResultsOverTimeCsv(array $statisticsOverTime, $metrics): void
    {
        $maxLengths = array_map('count', $statisticsOverTime);
        $maxLength = max($maxLengths);

        $metrics = ['f1', 'far', 'gscore'];
        foreach ($metrics as $metric) {
            file_put_contents($this->projectDir."/graphs/csv/results_over_time_{$metric}.csv", 'count;'.implode(';', $this->statsAnalyzers).PHP_EOL);
            for ($i = 1; $i < $maxLength; $i++) {
                $row = [];
                $row[] = $i;
                foreach ($this->statsAnalyzers as $analyzer) {
                    $row[] = $statisticsOverTime[$analyzer][$i][$metric] ?? 0;
                }
                file_put_contents($this->projectDir."/graphs/csv/results_over_time_{$metric}.csv", implode(';', $row).PHP_EOL, FILE_APPEND);
            }
        }
    }

    private function generateResultsTotalCsv(array $statistics, $metrics): void
    {
        if ($metrics) {
            $metrics = explode(',', $metrics);
        } else {
            $metrics = ['recall', 'specificity', 'f1'];
        }
        file_put_contents($this->projectDir.'/graphs/csv/results_total_metrics.csv', 'analyzer;'.implode(';', $metrics).PHP_EOL);

        foreach ($this->statsAnalyzers as $analyzer) {
            $row = [$analyzer];
            foreach ($metrics as $metric) {
                if ($metric === 'far') {
                    $row[] = 1 - $statistics[$analyzer][$metric];
                } else {
                    $row[] = $statistics[$analyzer][$metric];
                }
            }
            file_put_contents($this->projectDir.'/graphs/csv/results_total_metrics.csv', implode(';', $row).PHP_EOL, FILE_APPEND);
        }

        // nicer column names
        $searchReplace = [
            'truePositives' => 'TP',
            'trueNegatives' => 'TN',
            'falsePositives' => 'FP',
            'falseNegatives' => 'FN',
            'analyzer' => 'Tool',
            'recall' => 'Recall',
            'specificity' => 'Spezifität',
            'f1' => 'F1-Score',
            'psalm' => 'Psalm',
            'phan' => 'Phan',
            'snyk' => 'Snyk',
            'llama-32-8b' => 'Llama 3.2 8b',
            'gpt-3.5-turbo' => 'GPT 3.5 Turbo',
            'gpt-4o' => 'GPT 4o',
            'gpt-4o-mini' => 'GPT 4 mini',
            'time' => 'Zeit',
            'costs' => 'Kosten (in USD)',
            '(randomized)_wo_feedback' => 'OS',
            '(randomized)' => '',
            '"' => '',
        ];
        $csv = file_get_contents($this->projectDir.'/graphs/csv/results_total_metrics.csv');
        $csv = str_replace(array_keys($searchReplace), array_values($searchReplace), $csv);
        file_put_contents($this->projectDir.'/graphs/csv/results_total_metrics.csv', $csv);
    }
}
