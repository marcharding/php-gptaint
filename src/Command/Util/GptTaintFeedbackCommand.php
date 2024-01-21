<?php

namespace App\Command\Util;

use App\Entity\GptResult;
use App\Entity\Issue;
use App\Service\GptQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:gpt-taint:feedback',
    description: 'Query gpt with the code path fragments and get a probability on how expoitable the code it.',
)]
class GptTaintFeedbackCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private GptQuery $gptQuery;
    private string $projectDir;

    public function __construct(string $projectDir, EntityManagerInterface $entityManager, GptQuery $gptQuery)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
        $this->gptQuery = $gptQuery;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('issueId', InputArgument::REQUIRED, 'Issue id which should be analyzed (issue must be complete).')
            ->addArgument('gptResultId', InputArgument::OPTIONAL, 'Optional existing gpt result id to start the analysis.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $issueId = (int) $input->getArgument('issueId');
        $issue = $this->entityManager->getRepository(Issue::class)->find($issueId);

        $io->block("Starting analysis '{$issue->getCode()->getName()} / Internal id: {$issue->getCode()->getId()}", 'START', 'fg=yellow', '# ');

        if ($issue->getConfirmedState()) {
            $io->block('bad', 'CONFIRMED STATE', 'fg=red', '# ');
        } else {
            $io->block('good', 'CONFIRMED STATE', 'fg=green', '# ');
        }

        $io->block(PHP_EOL.PHP_EOL.$issue->getCode()->getIssues()->first()->getExtractedCodePath().PHP_EOL, 'CODE', 'fg=white', '# ');

        $io->block('Starting initial analysis.', 'INFO', 'fg=gray', '# ');

        $gptResultId = (int) $input->getArgument('gptResultId');
        if ($gptResultId) {
            $gptResult = $this->entityManager->getRepository(GptResult::class)->find($gptResultId);
        } else {
            $gptResult = $this->initialAnalysis($io, $issue);
        }

        $io->block('Results of initial analysis.', 'INFO', 'fg=gray', '# ');

        $io->block($gptResult->getAnalysisResult(), 'ANALYSIS RESULT', 'fg=white', '# ');

        $io->block($gptResult->getExploitExample(), 'EXPLOIT', 'fg=white', '# ');

        $io->block('Starting feedback loop.', 'INFO', 'fg=gray', '# ');

        $result = $this->startFeedbackLoop($io, $issue, $gptResult, $previousMessages = []);

        $io->block('', 'SANDBOX RESPONSE', 'fg=green', '# ');
        $io->block($result['gptResult']->getExploitExample(), 'SUCCESSFUL EXPLOIT', 'fg=black;bg=green', ' ', true);
        $io->block(trim($result['sandboxResult']));

        return Command::SUCCESS;
    }

    public function gptQuery($io, $issue, $messages = [], $additionalFunctions = [])
    {
        if (empty($messages)) {
            $io->block("Starting analysis '{$issue->getCode()->getName()}/{$issue->getCode()->getId()}", 'GPT', 'fg=gray', '# ');
        } else {
            $numberOfUserMessage = count(array_filter($messages, fn ($message) => $message['role'] === 'user'));
            $io->block("Refine or confirm (Iteration {$numberOfUserMessage}) for '{$issue->getCode()->getName()}/{$issue->getCode()->getId()}", 'GPT', 'fg=gray', '# ');
        }

        $counter = 0;
        do {
            try {
                $gptResult = $this->gptQuery->queryGpt($issue, true, 1, null, $messages, $additionalFunctions);
            } catch (\Exception $e) {
                $io->error("Exception {$e->getMessage()} / {$issue->getCode()->getName()} / {$issue->getType()} [Code-ID {$issue->getCode()->getId()}, Issue-ID: {$issue->getId()}]");

                return false;
            }
            $counter++;
        } while (!($gptResult instanceof GptResult) && $counter <= 5);

        if (!($gptResult instanceof GptResult)) {
            $io->error("{$issue->getCode()->getName()} / {$issue->getType()} [Code-ID {$issue->getCode()->getId()}, Issue-ID: {$issue->getId()}]");

            return false;
        }

        $this->entityManager->persist($gptResult);
        $this->entityManager->flush();

        return $gptResult;
    }

    public function setupSandbox($issue, $gptResult)
    {
        // get source directory of sample
        $finder = new Finder();
        $sourceDirectories = $finder->files()->in("{$this->projectDir}/data/nist/samples_selection")->directories()->name($issue->getCode()->getDirectory())->getIterator();
        $sourceDirectories->rewind();
        $sourceDirectory = $sourceDirectories->current()->getRealPath();

        // find sample files (named index.php or sample.php)
        $finder = new Finder();
        $sourceFiles = $finder->in($sourceDirectory)->files()->name(['sample.php', 'index.php'])->getIterator();
        $sourceFiles->rewind();
        $sourceFile = $sourceFiles->current()->getRealPath();

        // rename to index.php for easier prompt and more consistent results
        $targetFile = "{$this->projectDir}/sandbox/public/index.php";

        $filesystem = new Filesystem();
        $filesystem->copy($sourceFile, $targetFile, true);

        // remove comments
        $targetFileContent = preg_replace('#<!--(.*?)-->#ism', '', file_get_contents($targetFile));
        file_put_contents($targetFile, $targetFileContent);

        // setup mysql database
        $sqlFile = "{$sourceDirectory}/init.sql";
        if (is_file($sqlFile)) {
            $dockerfile = file_get_contents("{$sourceDirectory}/Dockerfile");
            if (strpos($dockerfile, 'sqlite3') !== false) {
                if (is_file("{$this->projectDir}/db/database.db")) {
                    unlink("{$this->projectDir}/db/database.db");
                }
                system("sqlite3 -init $sqlFile {$this->projectDir}/db/database.db '.exit'");
            } else {
                system("mysql -hmysql -uroot -e 'DROP DATABASE IF EXISTS myDB;'");
                system("mysql -hmysql -uroot < {$sqlFile}");
            }
        }

        $process = Process::fromShellCommandline($gptResult->getExploitExample());
        $process->run();

        if (!$process->isSuccessful()) {
            return false;
        }

        return $process->getOutput();
    }

    public function startFeedbackLoop($io, $issue, $gptResult, $messages = [])
    {
        if ($gptResult) {
            // get the sandbox result to provide some kind of ground truth to the llm
            $sandboxResult = $this->setupSandbox($issue, $gptResult);

            $io->block('', 'SANDBOX RESPONSE', 'fg=gray', '# ');
            $io->section($gptResult->getExploitExample());
            $io->block(trim($sandboxResult));

            $prompt = <<<EOT
Your example exploit, which you can find at the end of this message after "# Executed example exploit:" was executed against a research sandbox with the response of the sandbox at the end of this message after "# Response of sandbox:". 

Adjust and improve or confirm example exploit based on the response if sandbox.
 
Improve the example exploit based on the response of the sandbox. Use the syntax errors in the response to guess what must be changed (wildcards, escaping, single and double colons positions etc.) about the exploit to make it work.

Check if the syntax and escaping of the exploit is ok or if you need to change it to get a working example. Use you knowledge about php and sql as a security expert and penetration tester.

Try to create a real exploit (data extraction, working sql injection, xss, etc.) for education and not just provoke an error response of the sandbox. 

Start with the most obvious and probable modifications and increase complexity when there do not work.

Very important and critical is that you, under no circumstances, return the current or one of the previous executed example exploit again without major modifications! 

When the previous exploit example performed better (e.g. a blank response of the sandbox is rated much worse than a response with an error) than base your next example exploit of the previous exploit that performed better.

The example exploit must be a curl request.

Check that the curl command syntax of the example is correct!
 
Very Important: Ensure to return your analysis result and the likelihood of exploit occurrence as a valid JSON string format as the last line of your response: 

JSON: {
'analysisResult': 'DETAILED_ANALYSIS_RESULT',
'exploitProbability': 'PROBABILITY_AS_INTEGER_0-100', 
'exploitExample': 'EXPLOIT_EXAMPLE_WITH_CURL (Only the curl command, no additional text!)', 
'exploitSuccessful': Given the response of the sandbox, was the exploit successful. Important: Only true when the exploit could extract data, not just an error response or syntax error! An empty response or just and syntax error is not an successful Exploit!',
'exploitSeeminglySuccessful': 'Given the response of the sandbox, was the exploit successful, e.g. syntax error or similar.'
 }.
 
# Executed example exploit:
{$gptResult->getExploitExample()}

# Response of sandbox:
$sandboxResult;
EOT;

            foreach ($gptResult->getMessage() as $item) {
                $messages[] = $item;
            }

            $messages[] = [
                'role' => 'user',
                'content' => $prompt,
            ];

            $functions = [
                'exploitSuccessful' => [
                    'type' => 'boolean',
                    'description' => 'Given the response of the sandbox, was the exploit successful. Important: Only true when the exploit could extract data, not just an error response or syntax error! An empty response or just and syntax error is not an successful Exploit!',
                ],
                'exploitSeeminglySuccessful' => [
                    'type' => 'boolean',
                    'description' => 'Given the response of the sandbox, was the exploit successful, e.g. syntax error or similar.',
                ],
            ];
        }

        $numberOfUserMessage = count(array_filter($messages, fn ($message) => $message['role'] === 'user'));

        $gptResult = $this->gptQuery($io, $issue, $messages, $functions ?? []);

        if ($gptResult->isExploitExampleSuccessful() || $numberOfUserMessage > 10) {
            return [
                'gptResult' => $gptResult,
                'sandboxResult' => $sandboxResult,
                'messages' => $messages,
            ];
        }

        // remove the last message because we already added it above
        //   $messages[] = [
        //       'role' => 'user',
        //       'content' => $prompt,
        //   ];
        // and will add exactly this message again, when adding it from the gptMessage in the next lookup
        array_pop($messages);

        return $this->startFeedbackLoop($io, $issue, $gptResult, $messages);
    }

    public function initialAnalysis($io, $issue)
    {
        $counter = 0;
        $temperature = 0;
        do {
            try {
                $gptResult = $this->gptQuery->queryGpt($issue, true, $temperature);
            } catch (\Exception $e) {
                $io->error("Exception {$e->getMessage()} / {$issue->getCode()->getName()} / {$issue->getType()} [Code-ID {$issue->getCode()->getId()}, Issue-ID: {$issue->getId()}]");

                return;
            }
            $temperature = 0.00 + rand(0, 100) * 0.0005;
            $counter++;
        } while (!($gptResult instanceof GptResult) && $counter <= 3);

        if (!($gptResult instanceof GptResult)) {
            $io->error("{$issue->getCode()->getName()} / {$issue->getType()} [Code-ID {$issue->getCode()->getId()}, Issue-ID: {$issue->getId()}]");

            return;
        }

        $this->entityManager->persist($gptResult);
        $this->entityManager->flush();

        return $gptResult;
    }
}
