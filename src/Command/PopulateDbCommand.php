<?php

namespace App\Command;

use App\Entity\Code;
use App\Entity\Issue;
use App\Service\CodeExtractor\CodeExtractor;
use App\Service\SarifToFlatArrayConverter;
use App\Service\TaintTypes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand(
    name: 'app:populate-db',
    description: 'Populate db with code and issues.',
)]
class PopulateDbCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private string $projectDir;
    private CodeExtractor $codeExtractor;

    public function __construct(string $projectDir, EntityManagerInterface $entityManager, CodeExtractor $codeExtractor)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
        $this->codeExtractor = $codeExtractor;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory to iterate over (relative to the project dir)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $this->projectDir.DIRECTORY_SEPARATOR.$input->getArgument('directory');

        if (!is_dir($directory)) {
            $io->error('The provided directory does not exist.');

            return Command::FAILURE;
        }

        $iterator = new \DirectoryIterator($directory);

        $provider = new EncoderProvider();
        $encoder = $provider->get('cl100k_base');

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                $folderName = $fileInfo->getFilename();

                // Extract pluginname from readme.txt
                $name = $this->extractPluginnameFromReadme($fileInfo);

                $io->info("$name ($folderName)");

                $s = new SarifToFlatArrayConverter();
                $psalmResultFile = $this->projectDir.DIRECTORY_SEPARATOR.'data/wordpress/sarif'.DIRECTORY_SEPARATOR.$folderName.'.sarif';
                $psalmResultFile = json_decode(file_get_contents($psalmResultFile), true);
                $sarifResults = $s->getArray($psalmResultFile);

                // Add or update code entity
                $codeEntity = $this->entityManager->getRepository(Code::class)->findOneBy(['directory' => $folderName]);
                if (!$codeEntity) {
                    $codeEntity = new Code();
                }
                $codeEntity->setName($name);
                $codeEntity->setDirectory($folderName);
                $codeEntity->setType('wordpressPlugin');

                $this->entityManager->persist($codeEntity);
                $this->entityManager->flush();

                $issues = $this->getPsalmResultsArray($folderName);
                foreach ($issues as $issue) {
                    $issueEntity = $this->entityManager->getRepository(Issue::class)->findOneBy(['taintId' => $issue['errorId'], 'file' => $issue['file'], 'code' => $codeEntity]);
                    if (!$issueEntity) {
                        $issueEntity = new Issue();
                    }
                    $issueEntity->setCode($codeEntity);
                    $issueEntity->setTaintId($issue['errorId']);
                    $issueEntity->setType($issue['errorType']);
                    $issueEntity->setFile($issue['file']);
                    $issueEntity->setDescription($issue['description']);
                    $issueEntity->setPsalmResult($issue['content']);
                    $io->text($folderName.DIRECTORY_SEPARATOR.$issue['file']);

                    // store optimized codepath and unoptimized for token comparison / effectiveness
                    $extractedCodePath = '';
                    $unoptimizedCodePath = '';
                    if (isset($sarifResults[$issue['errorId'].'_'.$issue['file']])) {
                        $entry = $sarifResults[$issue['errorId'].'_'.$issue['file']];
                        foreach ($entry['locations'] as $item) {
                            $pluginRoot = $this->projectDir.DIRECTORY_SEPARATOR.'data/wordpress/plugins_tainted'.DIRECTORY_SEPARATOR.$folderName.DIRECTORY_SEPARATOR;
                            $extractedCodePath .= "// FILE: {$item['file']}".PHP_EOL.PHP_EOL.PHP_EOL;
                            $extractedCodePath .= $this->codeExtractor->extractCodeLeadingToLine($pluginRoot.$item['file'], $item['region']['startLine']);
                            $extractedCodePath .= PHP_EOL.PHP_EOL.PHP_EOL;
                            $unoptimizedCodePath .= file_get_contents($pluginRoot.$item['file']);
                        }
                    }

                    $issueEntity->setExtractedCodePath($extractedCodePath);
                    $issueEntity->setEstimatedTokens(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $extractedCodePath))));
                    $issueEntity->setEstimatedTokensUnoptimized(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $unoptimizedCodePath))));

                    $this->entityManager->persist($issueEntity);
                    $this->entityManager->flush();
                }
            }
            $this->entityManager->flush();
        }

        $this->entityManager->flush();

        $io->success('Db populated.');

        return Command::SUCCESS;
    }

    public function extractPluginnameFromReadme(\SplFileInfo $fileInfo): string
    {
        $readmeFile = $fileInfo->getPathname().'/readme.txt';

        if (!is_file($fileInfo->getPathname().'/readme.txt')) {
            return $fileInfo->getBasename();
        }

        $readmeContent = file_get_contents($readmeFile);
        preg_match('/===\s*([^=]+)\s*===/', $readmeContent, $matches);

        if (empty($matches)) {
            return $fileInfo->getBasename();
        }

        $name = trim($matches[1]);

        return $name;
    }

    public function getPsalmResultsArray($folderName): array
    {
        $psalmResultFile = $this->projectDir.DIRECTORY_SEPARATOR.'data/wordpress/results'.DIRECTORY_SEPARATOR.$folderName.'.txt';
        $text = file_get_contents($psalmResultFile);

        // Remove the psalm footer section
        $footerPattern = '/\n-{12,}\n.*?$/s';
        $text = preg_replace($footerPattern, '', $text);

        $pattern = '/ERROR: ([\w\s]+) - (\S+):(\d+:\d+) - (.+?)\n([\s\S]+?)(?=\n\nERROR|$)/';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        $errors = [];
        foreach ($matches as $match) {
            $errors[] = [
                'errorType' => $match[1],
                'errorId' => TaintTypes::getIdByName($match[1]),
                'file' => $match[2].':'.$match[3],
                'description' => $match[4],
                'content' => $match[5],
            ];
        }

        return $errors;
    }
}
