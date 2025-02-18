<?php

namespace App\Command\Nist;

use App\Entity\AnalysisResult;
use App\Entity\Sample;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand(
    name: 'app:sample:analyze:static',
    description: 'Analyse all samples in the given directory with the available static analyzers.',
)]
class SampleAnalyzeStaticCommand extends Command
{
    private string $projectDir;
    private EntityManagerInterface $entityManager;

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDirectory', InputArgument::OPTIONAL, 'The input source directories from which the samples are to be analyzed.', $this->projectDir.'/data/samples-selection/nist')
            ->addOption('analyzeTypes', null, InputOption::VALUE_OPTIONAL, 'Which analyzer should be used?', 'all');
    }

    public function __construct($projectDir, EntityManagerInterface $entityManager)
    {
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $provider = new EncoderProvider();
        $encoder = $provider->get('cl100k_base');

        $sourceDirectory = $input->getArgument('sourceDirectory');

        $analyzeTypesActive = [
            'psalm' => true,
            'phan' => true,
            'snyk' => true,
        ];

        $analyzeTypes = $input->getOption('analyzeTypes');
        if ($analyzeTypes !== 'all') {
            $analyzeTypesActive = array_map(function () {
                return false;
            }, $analyzeTypesActive);
            $analyzeTypes = explode(',', $analyzeTypes);
            foreach ($analyzeTypes as $analyzeType) {
                $analyzeTypesActive[$analyzeType] = true;
            }
        }

        if ($analyzeTypesActive['psalm'] === true) {
            exec('vendor/bin/psalm --version', $psalmVersion);
            if (preg_match('#\b(\d+\.\d+\.\d+)\b#', $psalmVersion[0], $matches)) {
                $psalmVersion = $matches[1];
            }
        }

        if ($analyzeTypesActive['snyk'] === true) {
            exec('snyk --version', $psalmVersion);
            if (preg_match('#\b(\d+\.\d+\.\d+)\b#', $psalmVersion[0], $matches)) {
                $snykVersion = $matches[1];
            }
        }

        if ($analyzeTypesActive['phan'] === true) {
            exec(' vendor/phan/phan/phan --version', $psalmVersion);
            if (preg_match('#\b(\d+\.\d+\.\d+)\b#', $psalmVersion[0], $matches)) {
                $phanVersion = $matches[1];
            }
        }

        $di = new \DirectoryIterator($sourceDirectory);

        foreach ($di as $directory) {
            if (!is_file("{$directory->getRealPath()}/manifest.sarif")) {
                continue;
            }

            $sarifManifestContent = file_get_contents("{$directory->getRealPath()}/manifest.sarif");
            $sarifManifest = json_decode($sarifManifestContent, true);

            $testCase = $directory->getRealPath();
            $io->writeln(PHP_EOL);
            $io->writeln('Sample '.basename($testCase));

            $cweId = null;
            if (isset($sarifManifest['runs'][0]['results'][0])) {
                $cweId = (int) $sarifManifest['runs'][0]['results'][0]['taxa'][0]['id'];
            }
            $state = $sarifManifest['runs'][0]['properties']['state'];

            // simple stripped down, only one file, just to calculate the toknes
            $testCasePhpFiles = glob("{$testCase}/src/*.php");
            $testCasePhpFile = reset($testCasePhpFiles);

            // create file with obfuscated names for llms analyzation
            copy($testCasePhpFile, "{$testCasePhpFile}.obfuscated.php");
            exec("php vendor/bin/rector process {$testCasePhpFile}.obfuscated.php 2>&1", $rectorResult);
            rename("{$testCasePhpFile}.obfuscated.php", "{$testCasePhpFile}.obfuscated");

            // normalize filepath based on project root
            $pattern = '#^'.preg_quote($this->projectDir, '/').'#';
            $normalizedCestCasePhpFile = preg_replace($pattern, '', $testCasePhpFile, 1);

            // Add or update code entity
            $issueEntity = $this->entityManager->getRepository(Sample::class)->findOneBy(['filepath' => $normalizedCestCasePhpFile]);
            if (!$issueEntity) {
                $issueEntity = new Sample();
            }

            $stopwatch = new Stopwatch();

            // Psalm run
            $psalmConfig = <<<'EOT'
<?xml version="1.0"?>
<psalm
        errorLevel="1"
        resolveFromConfigFile="true"
        findUnusedCode="false"
        findUnusedBaselineEntry="false"
        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xmlns="https://getpsalm.org/schema/config"
        xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
>
    <projectFiles>
        <directory name="%folder%" />
    </projectFiles>
</psalm>
EOT;
            if ($analyzeTypesActive['psalm'] === true) {
                $stopwatch->start('psalm');
                $io->writeln('Psalm is analysing '.basename($testCase));
                $psalmConfigXml = $this->projectDir.'/var/psalm.xml';
                file_put_contents($psalmConfigXml, strtr($psalmConfig, ['%folder%' => "{$testCase}/src/"]));
                $psalmResult = [];
                $time = microtime(true);
                exec("vendor/bin/psalm --config={$psalmConfigXml} --no-suggestions --monochrome --no-progress --taint-analysis", $psalmResult);
                $timeElapsed = round(microtime(true) - $time, 4);
                $psalmResult[] = PHP_EOL."# {$timeElapsed} seconds";

                // true when no errors were found, false when there were errors
                $psalmResultBool = false !== strpos(implode(PHP_EOL, $psalmResult), 'No errors found!');
                $io->writeln('Psalm result is "'.($psalmResultBool === false ? 'Taint found' : 'No taint found').'"');
                $psalmTime = $stopwatch->stop('psalm');
            }
            // /Psalm run

            // Snyk run (if TOKEN is available)
            if ($analyzeTypesActive['snyk'] === true) {
                if (getenv('SNYK_TOKEN')) {
                    $stopwatch->start('snyk');
                    $io->writeln('Snyk is analysing '.basename($testCase));
                    $snykResult = [];
                    $time = microtime(true);
                    exec("cd {$testCase}/src/ && snyk code test", $snykResult);
                    $timeElapsed = round(microtime(true) - $time, 4);
                    $snykResult[] = PHP_EOL."# {$timeElapsed} seconds";

                    // true when no errors were found, false when there were errors
                    $snykResultBool = false !== strpos(implode(PHP_EOL, $snykResult), 'No issues were found');
                    $io->writeln('Snyk result is "'.($snykResultBool === false ? 'Taint found' : 'No taint found').'"');
                    $snykTime = $stopwatch->stop('snyk');
                }
            }
            // /Snyk run

            // Phan run
            if ($analyzeTypesActive['phan'] === true) {
                $stopwatch->start('phan');
                $phanFileList = $this->projectDir.'/var/phan.txt';
                file_put_contents($phanFileList, $testCasePhpFile);
                $io->writeln('Phan is analysing '.basename($testCase));
                $phanResult = [];
                $time = microtime(true);

                $exec = "php vendor/phan/phan/phan --config-file vendor/mediawiki/phan-taint-check-plugin/scripts/generic-config.php --output 'php://stdout' --allow-polyfill-parser --no-progress-bar --output-mode=text --file-list-only {$phanFileList}";

                exec("$exec", $phanResult);
                $timeElapsed = round(microtime(true) - $time, 4);
                $phanResult[] = PHP_EOL."# {$timeElapsed} seconds";
                // true when no errors were found, false when there were errors
                $phanResultBool = false === strpos(implode(PHP_EOL, $phanResult), 'SecurityCheck');
                $io->writeln('Phan result is "'.($phanResultBool === false ? 'Taint found' : 'No taint found').'"');
                $phanTime = $stopwatch->stop('phan');
            }
            // /Snyk run

            $strippedDownTestCase = php_strip_whitespace($testCasePhpFile);
            $strippedDownTestCase = preg_replace('/<!--(.|\s)*?-->/', '', $strippedDownTestCase);
            $strippedDownTestCase = trim($strippedDownTestCase);
            $strippedDownTestCase = str_replace('; ', ';'.PHP_EOL, $strippedDownTestCase);
            $code = $strippedDownTestCase;

            $strippedDownTestCase = php_strip_whitespace("{$testCasePhpFile}.obfuscated");
            $strippedDownTestCase = preg_replace('/<!--(.|\s)*?-->/', '', $strippedDownTestCase);
            $strippedDownTestCase = trim($strippedDownTestCase);
            $strippedDownTestCase = str_replace('; ', ';'.PHP_EOL, $strippedDownTestCase);
            $codeObfuscated = $strippedDownTestCase;

            $regex = '/<!--\n#(?P<comment>.+?)\n-->/s';
            if (preg_match($regex, file_get_contents($testCasePhpFile), $matches)) {
                $note = $matches['comment'];
            } else {
                $note = 'Description not found.';
            }

            $issueEntity->setName(basename($testCase));
            $issueEntity->setFilepath($normalizedCestCasePhpFile);
            $issueEntity->setCweId($cweId);
            $issueEntity->setFile(basename($normalizedCestCasePhpFile));
            $issueEntity->setNote($note);
            $issueEntity->setConfirmedState($state === 'bad' ? Sample::StateBad : Sample::StateGood);
            $issueEntity->setCode($code);
            $issueEntity->setCodeObfuscated($codeObfuscated);
            $issueEntity->setEstimatedTokens(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $code))));
            $issueEntity->setEstimatedTokensUnoptimized(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $code))));
            $this->entityManager->persist($issueEntity);
            $this->entityManager->flush();

            if ($analyzeTypesActive['psalm'] === true) {
                $analysisResult = new AnalysisResult();
                $analysisResult->setIssue($issueEntity);
                $analysisResult->setAnalyzer('psalm');
                $analysisResult->setResultState($psalmResultBool === false ? Sample::StateBad : Sample::StateGood);
                $analysisResult->setAnalysisResult(implode(PHP_EOL, $psalmResult));
                $analysisResult->setTime($psalmTime->getDuration());
                $analysisResult->setAnalyzerVersion($psalmVersion);
                $this->entityManager->persist($analysisResult);
                $this->entityManager->flush();
            }
            if ($analyzeTypesActive['snyk'] === true && isset($snykResult)) {
                $analysisResult = new AnalysisResult();
                $analysisResult->setIssue($issueEntity);
                $analysisResult->setAnalyzer('snyk');
                $analysisResult->setResultState($snykResultBool === false ? Sample::StateBad : Sample::StateGood);
                $analysisResult->setAnalysisResult(implode(PHP_EOL, $snykResult));
                $analysisResult->setTime($snykTime->getDuration());
                $analysisResult->setAnalyzerVersion($snykVersion);
                $this->entityManager->persist($analysisResult);
                $this->entityManager->flush();
            }
            if ($analyzeTypesActive['phan'] === true) {
                $analysisResult = new AnalysisResult();
                $analysisResult->setIssue($issueEntity);
                $analysisResult->setAnalyzer('phan');
                $analysisResult->setResultState($phanResultBool === false ? Sample::StateBad : Sample::StateGood);
                $analysisResult->setAnalysisResult(implode(PHP_EOL, $phanResult));
                $analysisResult->setTime($phanTime->getDuration());
                $analysisResult->setAnalyzerVersion($phanVersion);
                $this->entityManager->persist($analysisResult);
                $this->entityManager->flush();
            }
        }

        $io->success('Finished.');

        return Command::SUCCESS;
    }
}
