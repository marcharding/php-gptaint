<?php

namespace App\Command;

use App\Entity\Issue;
use App\Message\GptMessageSync;
use App\Service\TaintTypes;
use Doctrine\ORM\EntityManagerInterface;
use OpenAI;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:query-gpt',
    description: 'Query gpt with the code path fragments and get a probability on how expoitable the code it.',
)]
class QueryGptCommand extends Command
{

    private EntityManagerInterface $entityManager;

    private MessageBusInterface $bus;
    protected OpenAI\Client $openAiClient;
    private string $projectDir;

    public function __construct(string $projectDir, EntityManagerInterface $entityManager, $openAiToken, MessageBusInterface $bus)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
        $this->openAiClient = OpenAI::client($openAiToken);
        $this->bus = $bus;
    }

    protected function configure(): void
    {
        $this
            ->addOption('taintTypes', 't', InputArgument::OPTIONAL, 'Taint types which should be analyzed with GPT')
            ->addOption('issueId', 'i', InputArgument::OPTIONAL, 'Issue ID which should be analyzed with GPT')
            ->addOption('codeId', 'c', InputArgument::OPTIONAL, 'Code ID which issues should be analyzed with GPT')
            ->setHelp(self::displayHelp());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $taintTypes = $input->getOption('taintTypes');
        $issueId = $input->getOption('issueId');
        $codeId = $input->getOption('codeId');

        if (empty($taintTypes) && empty($issueId) && empty($codeId)) {
            $io->error("At least one option must be given.");
            return Command::INVALID;
        }

        $qb = $this->entityManager->getRepository(Issue::class)->createQueryBuilder('i');

        if (!empty($taintTypes)) {
            $qb->andWhere('i.taintId IN(:taintTypes)')
                ->setParameter('taintTypes', $taintTypes);
        }

        if (!empty($issueId)) {
            $qb->andWhere('i.id = :issueId')
                ->setParameter('issueId', $issueId);
        }

        if (!empty($codeId)) {
            $qb->andWhere('i.code = :codeId')
                ->setParameter('codeId', $codeId);
        }

        if (true) {
            $qb->leftJoin('i.gptResults', 'g')
                ->andWhere($qb->expr()->isNull('g.id'));
        }


        $qb->andWhere('i.code != :codeId')
            ->setParameter('codeId', 433);

        $issues = $qb->getQuery()->getResult();

        foreach ($issues as $issue) {
            $this->bus->dispatch(new GptMessageSync($issue->getId()));
        }

        $io->success(sprintf("Processed %s issues", count($issues)));

        return Command::SUCCESS;
    }

    public static function displayHelp(): string
    {
        $constants = "";
        foreach (TaintTypes::getConstants() as $constant => $taintType) {
            $constants .= $constant . ": " . $taintType . PHP_EOL;
        }
        return $constants;
    }

}
