<?php

namespace App\Command\Nist;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sample:stats',
    description: 'Add a short description for your command',
)]
class SampleStatsCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDirectories', InputArgument::REQUIRED, 'The input source directories from which to create samples.')
        ;
    }

    public function __construct()
    {
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

        $types = [];
        $cweIds = [];

        foreach ($sourceDirectories as $sourceDirectory) {
            $sourceDirectoryIterator = new \DirectoryIterator($sourceDirectory);
            $good = 0;
            $bad = 0;

            foreach ($sourceDirectoryIterator as $directory) {
                if (!is_file("{$directory->getRealPath()}/manifest.sarif")) {
                    continue;
                }

                $sarifManifestContent = file_get_contents("{$directory->getRealPath()}/manifest.sarif");
                $sarifManifest = json_decode($sarifManifestContent, true);

                $fileContent = file_get_contents("{$directory->getRealPath()}/readme.md");
                $metaData = $this->extractMetadata($fileContent);

                if (!isset($cweIds[$metaData['Patterns']['Context']])) {
                    $cweIds[$metaData['Patterns']['Context']] = [];
                }

                $cweId = $sarifManifest['runs'][0]['results'][0]['ruleId'];
                if (!isset($cweIds[$metaData['Patterns']['Context']][$cweId])) {
                    $cweIds[$metaData['Patterns']['Context']][$cweId] = 0;
                }
                ++$cweIds[$metaData['Patterns']['Context']][$cweId];

                $types['Source'][] = $metaData['Patterns']['Source'];
                $types['Sanitization'][] = $metaData['Patterns']['Sanitization'];
                $types['Filters complete'][] = $metaData['Patterns']['Filters complete'];
                $types['Context'][] = $metaData['Patterns']['Context'];
                $types['Sink'][] = $metaData['Patterns']['Sink'];
                $types['Dataflow'][] = $metaData['Patterns']['Dataflow'];

                if (str_contains($fileContent, 'State: Bad')) {
                    $bad++;
                }

                if (str_contains($fileContent, 'State: Good')) {
                    $good++;
                }
            }

            $types['Source'] = array_unique($types['Source']);
            $types['Sanitization'] = array_unique($types['Sanitization']);
            $types['Filters complete'] = array_unique($types['Filters complete']);
            $types['Context'] = array_unique($types['Context']);
            $types['Sink'] = array_unique($types['Sink']);
            $types['Dataflow'] = array_unique($types['Dataflow']);

            asort($types['Source']);
            asort($types['Sanitization']);
            asort($types['Filters complete']);
            asort($types['Context']);
            asort($types['Sink']);
            asort($types['Dataflow']);

            $io->section('Good/Bad split');
            $io->writeln("Good: {$good}");
            $io->writeln("Bad: {$bad}");

            $io->section('CWE');
            foreach ($cweIds as $context => $cweIdEntry) {
                $currentCweIds = array_key_first($cweIdEntry);
                $currentCweIdCount = reset($cweIdEntry);
                $io->writeln("Context {$context}");
                $io->writeln("Classified as {$currentCweIds}.");
                $io->writeln("{$currentCweIdCount} occurrences.");
                $io->write(PHP_EOL);
            }

            foreach ($types as $type => $items) {
                if (!is_array($items)) {
                    continue;
                }
                $count = count($items);
                $io->section("$type ($count)");
                foreach ($items as $item) {
                    $io->writeln($item);
                }
            }

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
