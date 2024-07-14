<?php

namespace App\Command\Nist;

use App\Repository\IssueRepository;
use App\Service\Stats;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    public function __construct(string $projectDir, Stats $statsService, IssueRepository $issueRepository)
    {
        $this->projectDir = $projectDir;
        $this->statsService = $statsService;
        $this->issueRepository = $issueRepository;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issues = $this->issueRepository->findAll();
        $statistics = $this->statsService->getStatistics($issues);

        $this->generateResultsOverTimeCsv($statistics['statisticsOverTime']);
        $this->generateResultsTotalCsv($statistics['statistics']);

        $output->writeln('Statistics generated successfully.');

        return Command::SUCCESS;
    }

    private function generateResultsOverTimeCsv(array $statisticsOverTime): void
    {
        $maxLengths = array_map('count', $statisticsOverTime);
        $maxLength = max($maxLengths);

        $statsAnalyzers = ['psalm', 'snyk', 'phan', 'gpt-4o (randomized)', 'llama-3-8b (randomized)', 'gpt-3.5-turbo-0125 (randomized)'];
        $metrics = ['f1', 'far', 'gscore'];

        foreach ($metrics as $metric) {
            file_put_contents($this->projectDir."/graphs/results_over_time_{$metric}.csv", 'count;'.implode(';', $statsAnalyzers).PHP_EOL);
            for ($i = 1; $i < $maxLength; $i++) {
                $row = [];
                $row[] = $i;
                foreach ($statsAnalyzers as $analyzer) {
                    $row[] = $statisticsOverTime[$analyzer][$i][$metric] ?? 0;
                }
                file_put_contents($this->projectDir."/graphs/results_over_time_{$metric}.csv", implode(';', $row).PHP_EOL, FILE_APPEND);
            }
        }
    }

    private function generateResultsTotalCsv(array $statistics): void
    {
        $metrics = ['f1', 'far', 'gscore', 'recall'];
        file_put_contents($this->projectDir.'/graphs/results_total.csv', 'analyzer;'.implode(';', $metrics).PHP_EOL);

        $statsAnalyzers = ['psalm', 'snyk', 'phan', 'gpt-4o (randomized)', 'llama-3-8b (randomized)', 'gpt-3.5-turbo-0125 (randomized)'];

        foreach ($statsAnalyzers as $analyzer) {
            $row = [$analyzer];
            foreach ($metrics as $metric) {
                $row[] = $statistics[$analyzer][$metric] ?? ($metric === 'far' ? 1 - ($statistics[$analyzer][$metric] ?? 0) : 0);
            }
            file_put_contents($this->projectDir.'/graphs/results_total_metrics.csv', implode(';', $row).PHP_EOL, FILE_APPEND);
        }
    }
}
