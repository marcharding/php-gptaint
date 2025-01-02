<?php

namespace App\Command\Nist;

use App\Repository\IssueRepository;
use App\Service\IssueStats;
use App\Service\Stats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:analysis:results:per:issue:export:csv',
    description: 'Generate statistics and write them to CSV files.'
)]
class SampleAnalysisResultsPerIssuesExportCommand extends Command
{
    private IssueStats $issueStats;
    private IssueRepository $issueRepository;
    private string $projectDir;
    private array $statsAnalyzers = [
        'gpt-4o',
        'gpt-4o-mini',
        'llama-3-8b',
        'gpt-3.5-turbo-0125',
        'gpt-4o',
        'llama-3-8b',
        'gpt-3.5-turbo-0125',
        'gpt-4o_os',
        'llama-3-8b_os',
        'gpt-3.5-turbo-0125_os',
        'gpt-4o_os',
        'llama-3-8b_os',
        'gpt-3.5-turbo-0125_os',
    ];

    public function __construct(string $projectDir, Stats $statsService, IssueStats $issueStats, IssueRepository $issueRepository, EntityManagerInterface $entityManager)
    {
        $this->projectDir = $projectDir;
        $this->statsService = $statsService;
        $this->issueRepository = $issueRepository;
        $this->issueStats = $issueStats;
        $this->entityManager = $entityManager;
        $modelValues = $this->entityManager->getConnection()
            ->executeQuery('SELECT DISTINCT analyzer FROM analysis_result')
            ->fetchAllAssociative();
        $modelValues = array_column($modelValues, 'analyzer');
        $modelValuesWoFeedback = array_map(function ($item) {
            return "{$item}_os";
        }, $modelValues);
        $modelValues = array_merge($modelValues, $modelValuesWoFeedback);
        $this->statsAnalyzers = $modelValues;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('not-obfuscated', null, InputOption::VALUE_NONE, 'Only use the not obfuscated versions')
            ->addOption('feedback', null, InputOption::VALUE_NONE, 'Only use the feedback versions')
            ->addOption('no-feedback', null, InputOption::VALUE_NONE, 'Only use the non-feedback versions')
            ->addOption('analyzer', null, InputOption::VALUE_OPTIONAL, 'Only use these analyzers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->filterAnalyzers($input);

        $issues = $this->issueRepository->findAll();
        $statistics = $this->issueStats->getStatistics($issues);

        $this->generateCsv($statistics);

        $output->writeln('Statistics generated successfully.');

        return Command::SUCCESS;
    }

    private function filterAnalyzers(InputInterface $input): void
    {
        $notObfuscated = $input->getOption('not-obfuscated');
        $feedback = $input->getOption('feedback');
        $noFeedback = $input->getOption('no-feedback');
        $onlyKeepTheseAnalyzers = $input->getOption('analyzer');

        if ($notObfuscated) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '(not obfuscated)') !== false);
        } elseif (!$notObfuscated) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '(not obfuscated)') === false);
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

    private function generateCsv(array $statisticsOverTime): void
    {
        $rows = [];
        $header = [];

        $possibleAnalyzers = reset($statisticsOverTime);
        foreach ($possibleAnalyzers['analyzers'] as $analyzer => $analyzerResults) {
            if (!in_array($analyzer, $this->statsAnalyzers)) {
                continue;
            }
            $header[] = "$analyzer";
        }
        asort($header);
        array_unshift($header, 'Sample');
        $rows[] = $header;

        foreach ($statisticsOverTime as $issue => $results) {
            $row = [];

            foreach ($results['analyzers'] as $analyzer => $analyzerResults) {
                if (!in_array($analyzer, $this->statsAnalyzers)) {
                    continue;
                }

                $result = array_search(1, $analyzerResults, true);
                if ($result == 'FP' || $result == 'FN') {
                    $result = '\textcolor{red}{'.$result.'}';
                }
                $row[] = "$result (".($analyzerResults['triesCount'] ?? 1).'/'.$analyzerResults['differentExploits'].')';
            }
            ksort($row);
            array_unshift($row, strtok($issue, '-'));
            $rows[] = $row;
        }

        $fp = fopen($this->projectDir.'/graphs/csv/results_per_issue_analyzer.csv', 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';');
        }
        fclose($fp);

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
            '"' => '',
        ];
        $csv = file_get_contents($this->projectDir.'/graphs/csv/results_per_issue_analyzer.csv');
        $csv = str_replace(array_keys($searchReplace), array_values($searchReplace), $csv);
        file_put_contents($this->projectDir.'/graphs/csv/results_per_issue_analyzer.csv', $csv);
    }
}
