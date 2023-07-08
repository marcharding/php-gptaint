<?php

namespace App\Command;

use App\Entity\Code;
use App\Entity\Issue;
use App\Service\TaintTypes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:populate-db',
    description: 'Populate db with code and issues.',
)]
class PopulateDbCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private string $projectDir;

    public function __construct(string $projectDir, EntityManagerInterface $entityManager)
    {
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Directory to iterate over (relative to the project dir)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $this->projectDir . DIRECTORY_SEPARATOR . $input->getArgument('directory');

        if (!is_dir($directory)) {
            $io->error('The provided directory does not exist.');
            return Command::FAILURE;
        }

        $iterator = new \DirectoryIterator($directory);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                $folderName = $fileInfo->getFilename();

                // Extract pluginname from readme.txt
                $name = $this->extractPluginnameFromReadme($fileInfo);

                // Add or update code entity
                $codeEntity = $this->entityManager->getRepository(Code::class)->findOneBy(['directory' => $folderName]);
                if (!$codeEntity) {
                    $codeEntity = new Code();
                }
                $codeEntity->setName($name);
                $codeEntity->setDirectory($folderName);

                $this->entityManager->persist($codeEntity);
                $this->entityManager->flush();

                $issues = $this->getPsalmResultsArray($folderName);
                foreach ($issues as $issue) {
                    // TODO: Check if issue exists
                    $issueEntity = new Issue();
                    $issueEntity->setCode($codeEntity);

                    $issueEntity->setTaintId($issue['errorId']);
                    $issueEntity->setType($issue['errorType']);
                    $issueEntity->setFile($issue['file']);
                    $issueEntity->setDescription($issue['description']);
                    $issueEntity->setPsalmResult($issue['content']);

                    // TODO: Integrate codeextractor
                    $issueEntity->setExtractedCodePath('// TODO');

                    $this->entityManager->persist($issueEntity);
                }
            }
            $this->entityManager->flush();
        }

        $this->entityManager->flush();

        $io->success('Db populated.');

        return Command::SUCCESS;
    }

    /**
     * @param $fileInfo
     * @return string
     */
    public function extractPluginnameFromReadme($fileInfo): string
    {
        $readmeFile = $fileInfo->getPathname() . '/readme.txt';
        $readmeContent = file_get_contents($readmeFile);
        preg_match('/===\s*([^=]+)\s*===/', $readmeContent, $matches);
        $name = trim($matches[1]);
        return $name;
    }

    /**
     * @param $folderName
     * @return array
     */
    public function getPsalmResultsArray($folderName): array
    {
        $psalmResultFile = $this->projectDir . DIRECTORY_SEPARATOR . 'data/wordpress/results' . DIRECTORY_SEPARATOR . $folderName . '.txt';
        $text = file_get_contents($psalmResultFile);

        // Remove the psalm footer section
        $footerPattern = '/\n-{12,}\n.*?$/s';
        $text = preg_replace($footerPattern, '', $text);

        $pattern = '/ERROR: ([\w\s]+) - (\S+):(\d+:\d+) - (.+?)\n([\s\S]+?)(?=\n\nERROR|$)/';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        $errors = [];
        foreach ($matches as $match) {
            $errors = [
                'errorType' => $match[1],
                'errorId' => TaintTypes::getIdByName($match[1]),
                'file' => $match[2] . ':' . $match[3],
                'description' => $match[4],
                'content' => $match[5]
            ];
        }

        return array($errors);
    }

}
