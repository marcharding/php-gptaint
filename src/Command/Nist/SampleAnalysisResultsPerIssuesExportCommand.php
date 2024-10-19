<?php

namespace App\Command\Nist;

use App\Repository\IssueRepository;
use App\Service\IssueStats;
use App\Service\Stats;
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
        'gpt-4o (randomized)',
        'gpt-4o-mini (randomized)',
        'llama-3-8b (randomized)',
        'gpt-3.5-turbo-0125 (randomized)',
        'gpt-4o',
        'llama-3-8b',
        'gpt-3.5-turbo-0125',
        'gpt-4o (randomized)_wo_feedback',
        'llama-3-8b (randomized)_wo_feedback',
        'gpt-3.5-turbo-0125 (randomized)_wo_feedback',
        'gpt-4o_wo_feedback',
        'llama-3-8b_wo_feedback',
        'gpt-3.5-turbo-0125_wo_feedback',
    ];

    public function __construct(string $projectDir, Stats $statsService, IssueStats $issueStats, IssueRepository $issueRepository)
    {
        $this->projectDir = $projectDir;
        $this->statsService = $statsService;
        $this->issueRepository = $issueRepository;
        $this->issueStats = $issueStats;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('randomized', null, InputOption::VALUE_NONE, 'Only use the randomized versions')
            ->addOption('no-randomized', null, InputOption::VALUE_NONE, 'Only use the non-randomized versions')
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

        $this->statsAnalyzers = array_merge($this->statsAnalyzers, ['psalm', 'snyk', 'phan']);
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
            $header[] = "$analyzer (Count)";
        }
        asort($header);
        array_unshift($header, 'issue');
        $rows[] = $header;

        foreach ($statisticsOverTime as $issue => $results) {
            $row = [];


            foreach ($results['analyzers'] as $analyzer => $analyzerResults) {
                if (!in_array($analyzer, $this->statsAnalyzers)) {
                    continue;
                }

                $result = array_search(1, $analyzerResults, true);
                $row[] = "$result (".($analyzerResults['triesCount'] ?? 1).')';
            }
            ksort($row);
            array_unshift($row, $issue);
            $rows[] = $row;
        }

        $fp = fopen($this->projectDir.'/graphs/csv/results_per_issue_analyzer.csv', 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';');
        }
        fclose($fp);
    }
}
