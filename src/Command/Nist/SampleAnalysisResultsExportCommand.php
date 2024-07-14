<?php

namespace App\Command\Nist;

use App\Repository\IssueRepository;
use App\Service\Stats;
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
    private array $statsAnalyzers = [
        'gpt-4o (randomized)',
        'llama-3-8b (randomized)',
        'gpt-3.5-turbo-0125 (randomized)',
        'gpt-4o',
        'llama-3-8b',
        'gpt-3.5-turbo-0125',
        'gpt-4o (randomized)_wo_feedback',
        'llama-3-8b (randomized)_wo_feedback',
        'gpt-3.5-turbo-0125 (randomized_wo_feedback',
        'gpt-4o_wo_feedback',
        'llama-3-8b_wo_feedback',
        'gpt-3.5-turbo-0125_wo_feedback',
    ];

    public function __construct(string $projectDir, Stats $statsService, IssueRepository $issueRepository)
    {
        $this->projectDir = $projectDir;
        $this->statsService = $statsService;
        $this->issueRepository = $issueRepository;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('randomized', null, InputOption::VALUE_NONE, 'Only use the randomized versions')
            ->addOption('no-randomized', null, InputOption::VALUE_NONE, 'Only use the non-randomized versions')
            ->addOption('feedback', null, InputOption::VALUE_NONE, 'Only use the feedback versions')
            ->addOption('no-feedback', null, InputOption::VALUE_NONE, 'Only use the non-feedback versions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->filterAnalyzers($input);

        $issues = $this->issueRepository->findAll();
        $statistics = $this->statsService->getStatistics($issues);

        $this->generateResultsOverTimeCsv($statistics['statisticsOverTime']);
        $this->generateResultsTotalCsv($statistics['statistics']);

        $output->writeln('Statistics generated successfully.');

        return Command::SUCCESS;
    }

    private function filterAnalyzers(InputInterface $input): void
    {
        $randomized = $input->getOption('randomized');
        $noRandomized = $input->getOption('no-randomized');
        $feedback = $input->getOption('feedback');
        $noFeedback = $input->getOption('no-feedback');

        if ($randomized) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '(randomized)') !== false);
        } elseif ($noRandomized) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '(randomized)') === false);
        }

        if ($feedback) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '_wo_feedback') === false);
        } elseif ($noFeedback) {
            $this->statsAnalyzers = array_filter($this->statsAnalyzers, fn ($analyzer) => strpos($analyzer, '_wo_feedback') !== false);
        }

        $this->statsAnalyzers = array_merge($this->statsAnalyzers, ['psalm', 'snyk', 'phan']);
        $this->statsAnalyzers = array_values($this->statsAnalyzers);
    }

    private function generateResultsOverTimeCsv(array $statisticsOverTime): void
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

    private function generateResultsTotalCsv(array $statistics): void
    {
        $metrics = ['f1', 'far', 'gscore', 'recall'];
        file_put_contents($this->projectDir.'/graphs/csv/results_total_metrics.csv', 'analyzer;'.implode(';', $metrics).PHP_EOL);

        foreach ($this->statsAnalyzers as $analyzer) {
            $row = [$analyzer];
            foreach ($metrics as $metric) {
                $row[] = $statistics[$analyzer][$metric] ?? ($metric === 'far' ? 1 - ($statistics[$analyzer][$metric] ?? 0) : 0);
            }
            file_put_contents($this->projectDir.'/graphs/csv/results_total_metrics.csv', implode(';', $row).PHP_EOL, FILE_APPEND);
        }
    }
}
