<?php

namespace App\Command\Nist;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:samples:create-randomize-test-set',
    description: 'Add a short description for your command',
)]
class SamplesRandomTestSetCommand extends Command
{
    private $projectDir;

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDirectories', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.', glob($this->projectDir.'/data/samples-all/nist/extracted/*', GLOB_ONLYDIR))
            ->addArgument('targetDirectory', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.', $this->projectDir.'/data/samples-selection/nist')
            ->addOption('amount', null, InputOption::VALUE_OPTIONAL, 'How many samples should be created.', 200);
    }

    public function __construct($projectDir)
    {
        $this->projectDir = $projectDir;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filesystem = new Filesystem();

        $amount = $input->getOption('amount');

        $sourceDirectories = $input->getArgument('sourceDirectories');
        if (!is_array($sourceDirectories)) {
            $sourceDirectories = explode(',', $sourceDirectories);
        }

        $targetDirectory = $input->getArgument('targetDirectory');
        $filesystem->mkdir($targetDirectory);

        foreach ($sourceDirectories as $sourceDirectory) {
            if (!is_dir($sourceDirectory)) {
                $io->error("The provided source directory $sourceDirectory does not exist.");

                return Command::FAILURE;
            }
        }

        $filesystem = new Filesystem();

        foreach ($sourceDirectories as $sourceDirectory) {
            $targetDirectoryBaseName = basename($sourceDirectory);

            $sortedTestCases = [];
            $sourceDirectoryIterator = new \DirectoryIterator($sourceDirectory);

            foreach ($sourceDirectoryIterator as $directory) {
                if (!is_file("{$directory->getRealPath()}/manifest.sarif")) {
                    continue;
                }

                // filer samples which are difficult to reproduce in out system due to the used database
                $filterFunctions = ['db2_', 'pg_', 'sqlsrv_'];
                $file = file_get_contents("{$directory->getRealPath()}/src/sample.php");
                foreach ($filterFunctions as $filterFunction) {
                    if (stripos($file, $filterFunction) !== false) {
                        continue 2;
                    }
                }

                $sarifManifestContent = file_get_contents("{$directory->getRealPath()}/manifest.sarif");
                $sarifManifest = json_decode($sarifManifestContent, true);

                $state = $sarifManifest['runs'][0]['properties']['state'];
                $sortedTestCases[$state][] = $directory->getRealPath();
            }

            $randomizedTestCases = [];
            foreach (array_keys($sortedTestCases) as $state) {
                shuffle($sortedTestCases[$state]);
                $randomizedTestCases[$state] = array_slice($sortedTestCases[$state], 0, ceil($amount / 2));
            }

            foreach ($randomizedTestCases as $state => $testCases) {
                foreach ($testCases as $testCase) {
                    $basename = basename($testCase);
                    $filesystem->mirror($testCase, "{$targetDirectory}/{$basename}/");
                }
            }

            $report = '';
            foreach ($randomizedTestCases as $state => $randomizedTestCase) {
                $report .= $state.': '.count($randomizedTestCase).' - ';
            }

            $io->success("$amount random samples for $$targetDirectoryBaseName ($report) selected.");
        }

        return Command::SUCCESS;
    }
}
