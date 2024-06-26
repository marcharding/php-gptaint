<?php

namespace App\MessageHandler;

use App\Entity\GptResult;
use App\Entity\Issue;
use App\Message\GptMessage;
use App\Message\GptMessageSync;
use App\Service\GptQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class BaseGptMessageHandler
{
    private EntityManagerInterface $entityManager;

    private GptQuery $gptQuery;

    public function __construct(EntityManagerInterface $entityManager, GptQuery $gptQuery)
    {
        $this->entityManager = $entityManager;
        $this->gptQuery = $gptQuery;
    }

    public function invoke(GptMessage|GptMessageSync $message)
    {
        $io = new SymfonyStyle(new ArgvInput(), new ConsoleOutput());
        $issue = $this->entityManager->getRepository(Issue::class)->findOneBy(['id' => $message->getId()]);

        $io->writeln("Querying GPT for plugin '{$issue->getName()}', issue id {$message->getId()}");

        $counter = 0;
        $temperature = 0;
        do {
            try {
                $gptResult = $this->gptQuery->queryGpt($issue, true, $temperature);
            } catch (\Exception $e) {
                $io->error("Exception {$e->getMessage()} / {$issue->getName()} / {$issue->getType()} [Code-ID {$issue->getId()}, Issue-ID: {$issue->getId()}]");

                return;
            }
            $temperature = 0.00 + rand(0, 100) * 0.0005;
            $counter++;
        } while (!($gptResult instanceof GptResult) && $counter <= 3);

        if (!($gptResult instanceof GptResult)) {
            $io->error("{$issue->getName()} / {$issue->getType()} [Code-ID {$issue->getId()}, Issue-ID: {$issue->getId()}]");

            return;
        }

        $this->entityManager->persist($gptResult);
        $this->entityManager->flush();

        $io->success("{$issue->getName()} / {$issue->getType()} [Code-ID {$issue->getId()}, Issue-ID: {$issue->getId()}]");
    }
}
