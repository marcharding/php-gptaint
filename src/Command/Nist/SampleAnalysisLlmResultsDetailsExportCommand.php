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
            ->addArgument('issueIds', InputArgument::OPTIONAL, 'Issue id which should be analyzed (issue must be complete).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issueId = (int) $input->getArgument('issueId');
        $issue = $this->issueRepository->findOne($issueId);
        $gptResults = $issue->getGptResults();

        foreach ($gptResults as $gptResult) {
            $groupedGptResults[$gptResult->getAnalyzer()][] = $gptResult;
        }

        $latexOutput = $this->twig->render('/helper/latexResult.html.twig', [
            'issue' => $issue,
            'results' => $groupedGptResults,
        ]);

        $output->write($latexOutput);

        return Command::SUCCESS;
    }
}
