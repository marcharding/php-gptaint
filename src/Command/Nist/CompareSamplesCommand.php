<?php

namespace App\Command\Nist;

use App\Entity\Code;
use App\Entity\Issue;
use App\Service\TaintTypes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yethee\Tiktoken\EncoderProvider;

#[AsCommand(
    name: 'app:nist:pslam',
    description: 'Add a short description for your command',
)]
class CompareSamplesCommand extends Command
{
    private $projectDir;
    private EntityManagerInterface $entityManager;

    protected function configure(): void
    {
        $this
            ->addArgument('sourceDirectory', InputArgument::REQUIRED, 'The input source directories from which the samples are to be analyzed.')
            ->addOption('taintType', null, InputOption::VALUE_OPTIONAL, 'Which taint type should be assigned?', 999);
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
        $taintType = $input->getOption('taintType');

        $di = new \DirectoryIterator($sourceDirectory);

        $sortedTestCases = [];

        foreach ($di as $directory) {
            if (!is_file("{$directory->getRealPath()}/manifest.sarif")) {
                continue;
            }

            $sarifManifestContent = file_get_contents("{$directory->getRealPath()}/manifest.sarif");
            $sarifManifest = json_decode($sarifManifestContent, true);

            $state = $sarifManifest['runs'][0]['properties']['state'];
            $sortedTestCases[$state][] = $directory->getRealPath();
        }

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

        foreach ($sortedTestCases as $state => $testCases) {
            foreach ($testCases as $testCase) {
                // Add or update code entity
                $codeEntity = $this->entityManager->getRepository(Code::class)->findOneBy(['directory' => basename($testCase)]);
                if (!$codeEntity) {
                    $codeEntity = new Code();
                }
                $codeEntity->setName(basename($testCase));
                $codeEntity->setDirectory(basename($testCase));
                $codeEntity->setType('NIST-Sample');
                $this->entityManager->persist($codeEntity);
                $this->entityManager->flush();

                $psalmConfigXml = $this->projectDir.'/var/psalm.xml';
                file_put_contents($psalmConfigXml, strtr($psalmConfig, ['%folder%' => "{$testCase}/src/"]));

                $result = [];
                $start = microtime(true);
                exec("vendor/bin/psalm --config={$psalmConfigXml} --no-suggestions --monochrome --no-progress --taint-analysis", $result);

                // true when no errors were found, false when there were errors
                $psalmResult = false !== strpos(implode(PHP_EOL, $result), 'No errors found!');

                // simple stripped down, only one file
                $testCasePhpFiles = glob("{$testCase}/src/*.php");
                $testCasePhpFile = reset($testCasePhpFiles);
                $strippedDownTestCase = php_strip_whitespace($testCasePhpFile);
                $strippedDownTestCase = preg_replace('/<!--(.|\s)*?-->/', '', $strippedDownTestCase);
                $strippedDownTestCase = preg_replace('/<!--.*?-->|<(?!\/?(?:php|\?))(?:(?<!\?)|(?<=\?)\/?)[^>]*>/', '', $strippedDownTestCase);
                $strippedDownTestCase = trim($strippedDownTestCase);
                $strippedDownTestCase = str_replace('; ', ';'.PHP_EOL, $strippedDownTestCase);

                $code = $strippedDownTestCase;

                $io->writeln('Psalm is analysing '.basename($testCase));
                $regex = '/<!--\n#(?P<comment>.+?)\n-->/s';

                if (preg_match($regex, file_get_contents($testCasePhpFile), $matches)) {
                    $note = $matches['comment'];
                } else {
                    $note = 'Description not found.';
                }

                $issueEntity = $this->entityManager->getRepository(Issue::class)->findOneBy(['taintId' => $taintType, 'file' => basename($testCasePhpFile), 'code' => $codeEntity]);
                if (!$issueEntity) {
                    $issueEntity = new Issue();
                }
                $issueEntity->setCode($codeEntity);
                $issueEntity->setTaintId($taintType);
                $issueEntity->setType(TaintTypes::getNameById($taintType));
                $issueEntity->setFile(basename($testCasePhpFile));
                $issueEntity->setDescription(TaintTypes::getNameById($taintType));
                $issueEntity->setNote($note);
                $issueEntity->setPsalmResult(implode(PHP_EOL, $result));
                $issueEntity->setConfirmedState($state === 'bad' ? Issue::StateBad : Issue::StateGood);
                $issueEntity->setPsalmState($psalmResult === false ? Issue::StateBad : Issue::StateGood);

                $issueEntity->setExtractedCodePath($code);
                $issueEntity->setEstimatedTokens(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $code))));
                $issueEntity->setEstimatedTokensUnoptimized(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $code))));

                $this->entityManager->persist($issueEntity);
                $this->entityManager->flush();
            }
        }

        $io->success('Finished.');

        return Command::SUCCESS;
    }
}
