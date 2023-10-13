<?php

namespace App\MessageHandler;

use App\Entity\Issue;
use App\Message\GptMessage;
use App\Service\GptQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class GptMessageHandler implements MessageHandlerInterface
{
    private EntityManagerInterface $entityManager;

    private GptQuery $gptQuery;

    public function __construct(EntityManagerInterface $entityManager, GptQuery $gptQuery)
    {
        $this->entityManager = $entityManager;
        $this->gptQuery = $gptQuery;
    }

    public function __invoke(GptMessage $message)
    {
        $io = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());
        $issue = $this->entityManager->getRepository(Issue::class)->findOneBy(['id' => $message->getId()]);

        $io->writeln("Querying GPT for plugin '{$issue->getCode()->getName()}', issue id {$message->getId()}");

        $gptResult = $this->gptQuery->queryGpt($issue, true);
        $this->entityManager->persist($gptResult);
        $this->entityManager->flush();

        $io->success("OK: {$issue->getCode()->getName()} / {$issue->getType()}");
    }
}
