<?php

namespace App\Command\Nist;

use App\Repository\IssueRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;

#[AsCommand(
    name: 'app:analysis:llm:details:results:export',
    description: 'Export the LLM analysis results as text/latex.'
)]
class SampleAnalysisLlmResultsDetailsExportCommand extends Command
{
    private IssueRepository $issueRepository;
    private Environment $twig;

    public function __construct(IssueRepository $issueRepository, Environment $twig)
    {
        $this->issueRepository = $issueRepository;
        $this->twig = $twig;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('issueIds', InputArgument::OPTIONAL, 'Comma-separated issue ids which should be analyzed (issues must be complete).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issueIds = $input->getArgument('issueIds');
        $ids = array_map('trim', explode(',', $issueIds)); // Split by commas and trim whitespace

        foreach ($ids as $issueId) {
            $groupedGptResults = [];
            $issue = $this->issueRepository->find((int) $issueId); // Retrieve each issue by ID

            if ($issue === null) {
                $output->writeln("Issue with ID $issueId not found.");
                continue; // Skip to the next ID
            }

            $gptResults = $issue->getGptResults();

            foreach ($gptResults as $gptResult) {
                if ($gptResult->getAnalyzer()) {
                    $groupedGptResults[$gptResult->getAnalyzer()][] = $gptResult;
                }
            }

            $latexOutput = $this->twig->render('/helper/latexResult.html.twig', [
                'issue' => $issue,
                'results' => $groupedGptResults,
            ]);

            $output->writeln($latexOutput);

        }

        return Command::SUCCESS;
    }
}
