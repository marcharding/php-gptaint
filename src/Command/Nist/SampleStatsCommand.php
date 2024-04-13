<?php

namespace App\Command\Nist;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sample:stats',
    description: 'Add a short description for your command',
)]
class SampleStatsCommand extends Command
{
    private $projectDir;

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDirectories', InputArgument::REQUIRED, 'The input source directories from which to create samples.')
            ->addOption('amount', null, InputOption::VALUE_OPTIONAL, 'How many samples should be created.', 100);
    }

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sourceDirectories = $input->getArgument('sourceDirectories');
        $sourceDirectories = explode(',', $sourceDirectories);

        foreach ($sourceDirectories as $sourceDirectory) {
            if (!is_dir($sourceDirectory)) {
                $io->error("The provided source directory $sourceDirectory does not exist.");

                return Command::FAILURE;
            }
        }

        $stats = [];

        foreach ($sourceDirectories as $sourceDirectory) {
            $sourceDirectoryIterator = new \DirectoryIterator($sourceDirectory);

            foreach ($sourceDirectoryIterator as $directory) {
                if (!is_file("{$directory->getRealPath()}/manifest.sarif")) {
                    continue;
                }

                $sarifManifestContent = file_get_contents("{$directory->getRealPath()}/manifest.sarif");
                $sarifManifest = json_decode($sarifManifestContent, true);

                $fileContent = file_get_contents("{$directory->getRealPath()}/readme.md");
                $metaData = $this->extractMetadata($fileContent);

                if (!isset($stats[$metaData['Patterns']['Context']])) {
                    $stats[$metaData['Patterns']['Context']] = [];
                }

                $cweId = $sarifManifest['runs'][0]['results'][0]['ruleId'];
                if (!isset($stats[$metaData['Patterns']['Context']][$cweId])) {
                    $stats[$metaData['Patterns']['Context']][$cweId] = 0;
                }
                ++$stats[$metaData['Patterns']['Context']][$cweId];
            }
            var_dump($stats);
            $io->success('Finished.');
        }

        return Command::SUCCESS;
    }

    protected function extractMetadata($fileContent)
    {
        // Initialize the array
        $result = [];

        // Patterns section
        $patternsStart = strpos($fileContent, 'Patterns:');
        $patternsEnd = strpos($fileContent, 'State:');
        $patternsContent = substr($fileContent, $patternsStart, $patternsEnd - $patternsStart);

        preg_match_all('/- (.*?): (.*?)$/m', $patternsContent, $matches, PREG_SET_ORDER);

        $patternsArray = [];
        foreach ($matches as $match) {
            $patternsArray[$match[1]] = $match[2];
        }

        // State section
        $stateStart = strpos($fileContent, 'State:');
        $stateEnd = strpos($fileContent, '# Exploit description');
        $stateContent = substr($fileContent, $stateStart, $stateEnd - $stateStart);

        preg_match_all('/- (.*?): (.*?)$/m', $stateContent, $matches, PREG_SET_ORDER);

        $stateArray = [];
        foreach ($matches as $match) {
            $stateArray[$match[1]] = $match[2];
        }

        // Exploit description
        $exploitDescriptionStart = strpos($fileContent, '# Exploit description') + strlen('# Exploit description');
        $exploitDescription = trim(substr($fileContent, $exploitDescriptionStart));

        // Populate the result array
        $result['Patterns'] = $patternsArray;
        $result['State'] = $stateArray;
        $result['Description'] = $exploitDescription;

        // Print the result array
        return $result;
    }
}
