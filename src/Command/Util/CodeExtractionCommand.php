<?php

namespace App\Command\Util;

use App\Entity\Issue;
use App\Service\CodeExtractor\CodeExtractor;
use App\Service\SarifToFlatArrayConverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand(
    name: 'app:code-extraction',
    description: 'Query gpt with the code path fragments and get a probability on how expoitable the code it.',
)]
class CodeExtractionCommand extends Command
{
    private EntityManagerInterface $entityManager;

    public function __construct(string $projectDir, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addOption('issueId', 'i', InputArgument::OPTIONAL, 'Issue id which should be analyzed with GPT');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $provider = new EncoderProvider();
        $encoder = $provider->get('cl100k_base');

        $qb = $this->entityManager->getRepository(Issue::class)->createQueryBuilder('i');
        $qb->andWhere('LENGTH(i.extractedCodePath) < 8');

        $issues = $qb->getQuery()->getResult();

        foreach ($issues as $issue) {
            $codeExtractor = new CodeExtractor();
            $folderName = $issue->getCode()->getDirectory();

            $s = new SarifToFlatArrayConverter();
            $psalmResultFile = $this->projectDir.DIRECTORY_SEPARATOR.'data/wordpress/sarif'.DIRECTORY_SEPARATOR.$folderName.'.sarif';
            $psalmResultFile = json_decode(file_get_contents($psalmResultFile), true);
            $sarifResults = $s->getArray($psalmResultFile);

            // store optimized codepath and unoptimized for token comparison / effectiveness
            $extractedCodePath = '';
            $unoptimizedCodePath = '';
            if (!isset($sarifResults[$issue->getTaintId().'_'.$issue->getFile()])) {
                $io->error($issue->getId());
                continue;
            }
            $entry = $sarifResults[$issue->getTaintId().'_'.$issue->getFile()];
            foreach ($entry['locations'] as $item) {
                $pluginRoot = $this->projectDir.DIRECTORY_SEPARATOR.'data/wordpress/plugins_tainted'.DIRECTORY_SEPARATOR.$folderName.DIRECTORY_SEPARATOR;
                $extractedCodePath .= "// FILE: {$item['file']}".PHP_EOL.PHP_EOL.PHP_EOL;
                $extractedCodePath .= $codeExtractor->extractCodeLeadingToLine($pluginRoot.$item['file'], $item['region']['startLine']);
                $extractedCodePath .= PHP_EOL.PHP_EOL.PHP_EOL;
                $unoptimizedCodePath .= file_get_contents($pluginRoot.$item['file']);
            }

            $issue->setExtractedCodePath($extractedCodePath);
            $issue->setEstimatedTokens(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $extractedCodePath))));
            $issue->setEstimatedTokensUnoptimized(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $unoptimizedCodePath))));

            $this->entityManager->persist($issue);
            $this->entityManager->flush();
            $io->success($issue->getId());
        }

        return Command::SUCCESS;
    }
}
