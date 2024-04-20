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
            ->addOption('amount', null, InputOption::VALUE_OPTIONAL, 'How many samples should be created.', 500);
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

                $fileContent = file_get_contents("{$directory->getRealPath()}/readme.md");
                $metaData = $this->extractMetadata($fileContent);

                $keep = [
                    'mysqli_real_query_method_prm__<$>(db)',
                    'print_func',
                    // 'pdo_prepare_prm__<$>(pdo)',
                    'vprintf_prm__<s>(This%s)',
                    // 'pg_query_prm__<$>(db)',
                    'trigger_error_prm__<c>(E_USER_ERROR)',
                    'pdo_query_prm__<$>(pdo)',
                    'mysqli_real_query_prm__<$>(db)',
                    'mysqli_prepare_prm__<$>(db)',
                    'printf_func_prm__<s>(Print this: %s)',
                    'vprintf_prm__<s>(This%d)',
                    'exit',
                    // 'db2_exec_prm__<$>(db)',
                    'printf_func_prm__<s>(Print this: %d)',
                    'echo_func',
                    // 'sqlsrv_query_prm__<$>(db)',
                    // 'db2_prepare_prm__<$>(db)',
                    'user_error_prm_',
                    // 'mssql_sqlsrv_prepare_prm__<$>(db)',
                    'mysqli_multi_query_prm__<$>(db)',
                    // 'pg_send_query_prm__<$>(db)',
                    'mysqli_multi_query_method_prm__<$>(db)',
                    // 'sqlite3_query_prm__<$>(db)',
                ];
                if (count(array_filter($keep, function ($item) use ($metaData) {
                    return str_starts_with($metaData['Patterns']['Sink'], $item);
                })) === 0) {
                    continue;
                }

                $keep = [
                    'sql_apostrophe',
                    'sql_quotes',
                    'xss_apostrophe',
                    'xss_html_param_a',
                    'xss_html_param',
                    'xss_javascript_no_enclosure',
                    'xss_javascript',
                    'xss_quotes',
                    'sql_plain',
                    'xss_plain',
                ];
                if (count(array_filter($keep, function ($item) use ($metaData) {
                    return str_starts_with($metaData['Patterns']['Context'], $item);
                })) === 0) {
                    continue;
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
                $report .= $state.': '.count($randomizedTestCase).' | ';
            }

            $io->success("$amount random samples for $$targetDirectoryBaseName ($report) selected.");
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
