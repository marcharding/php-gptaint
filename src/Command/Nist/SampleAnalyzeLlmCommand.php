<?php

namespace App\Command\Nist;

use App\Entity\GptResult;
use App\Entity\Issue;
use App\Service\GptQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:sample:analyze:llm',
    description: 'Query the given llm model and let it determine if the sample it attackable.',
)]
class SampleAnalyzeLlmCommand extends Command
{
    public const MAX_LOOPS = 5;
    private EntityManagerInterface $entityManager;
    private GptQuery $gptQueryService;
    private string $projectDir;
    private string $model;

    public function __construct(string $projectDir, EntityManagerInterface $entityManager, GptQuery $gptQuery)
    {
        parent::__construct();
        $this->projectDir = $projectDir;
        $this->entityManager = $entityManager;
        $this->gptQueryService = $gptQuery;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('issueId', InputArgument::OPTIONAL, 'Issue id which should be analyzed (issue must be complete).')
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Model to use (if none is given the default model from the configuration is used).')
            ->addArgument('gptResultId', InputArgument::OPTIONAL, 'Optional existing gpt result id to start the analysis.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $issueId = (int) $input->getArgument('issueId');
        $gptResultId = (int) $input->getArgument('gptResultId');
        if ($input->getOption('model')) {
            $this->gptQueryService->setModel($input->getOption('model'));
        }

        if ($gptResultId) {
            $gptResult = $this->entityManager->getRepository(GptResult::class)->find($gptResultId);
        }

        if ($issueId) {
            $issue = $this->entityManager->getRepository(Issue::class)->find($issueId);
            $this->startGptFeedbackLoop($io, $issue, $gptResult ?? null);
        }

        if (!$issueId) {
            $question = new ConfirmationQuestion('No issue id given, will process all issues! Do you want to continue? (yes/no) ', false);

            $helper = $this->getHelper('question');
            $answer = $helper->ask($input, $output, $question);

            if ($answer) {
                $output->writeln('Ok, starting analysis for all issues.');

                $issues = $this->entityManager->getRepository(Issue::class)->findAll();
                foreach ($issues as $issue) {
                    $this->startGptFeedbackLoop($io, $issue);
                }
            } else {
                $output->writeln('Canceled command.');

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param Issue|object|null $issue
     */
    public function startGptFeedbackLoop(SymfonyStyle $io, Issue|null $issue, GptResult $gptResult = null): void
    {
        $io->block("Starting analysis '{$issue->getName()} / Internal id: {$issue->getId()} / Model: {$this->gptQueryService->getModel()}", 'START', 'fg=yellow', '# ');

        if ($issue->getConfirmedState()) {
            $io->block('bad', 'CONFIRMED STATE', 'fg=red', '# ');
        } else {
            $io->block('good', 'CONFIRMED STATE', 'fg=green', '# ');
        }

        $io->block(PHP_EOL.PHP_EOL.$issue->getExtractedCodePath().PHP_EOL, 'CODE', 'fg=white', '# ');

        $io->block('Starting initial analysis.', 'INFO', 'fg=gray', '# ');

        if (!$gptResult) {
            $gptResult = $this->initialAnalysis($io, $issue);
            if ($gptResult === false) {
                $io->error('Could not analyse sample');
                return;
            }
        }

        $io->block('Results of initial analysis.', 'INFO', 'fg=gray', '# ');

        $io->block($gptResult->getAnalysisResult(), 'ANALYSIS RESULT', 'fg=white', '# ');

        if (strpos($gptResult->getExploitExample(), 'curl') !== false) {
            $io->block($gptResult->getExploitExample(), 'EXPLOIT', 'fg=white', '# ');
            $io->block('Starting feedback loop.', 'INFO', 'fg=gray', '# ');

            $result = $this->startFeedbackLoop($io, $issue, $gptResult, $previousMessages = []);

            $io->block('', 'FINAL SANDBOX RESPONSE', 'fg=cyan', '# ');

            if ($result['gptResult']->isExploitExampleSuccessful()) {
                $io->block($result['gptResult']->getExploitExample(), 'SUCCESSFUL EXPLOIT', 'fg=black;bg=green', ' ', true);
                $io->block(trim($result['sandboxResult']));
            } else {
                $io->block('good', 'ANALYZED STATE', 'fg=green', '# ');
                $io->block(trim($result['sandboxResult']));
            }
        } else {
            $io->block('good', 'ANALYZED STATE', 'fg=green', '# ');
        }
    }

    public function queryGpt($io, $issue, $messages = [], $additionalFunctions = [], GptResult $parentGptResult = null)
    {
        if (empty($messages)) {
            $io->block("Starting analysis '{$issue->getName()}/{$issue->getId()}", 'GPT', 'fg=gray', '# ');
        } else {
            $numberOfUserMessage = count(array_filter($messages, fn ($message) => $message['role'] === 'user'));
            $io->block("Refine or confirm (Iteration {$numberOfUserMessage}) for '{$issue->getName()}/{$issue->getId()}", 'GPT', 'fg=gray', '# ');
        }

        $counter = 0;
        do {
            try {
                $gptResult = $this->gptQueryService->queryGpt($issue, true, 1, $messages, $additionalFunctions, $parentGptResult);
            } catch (\Exception $e) {
                $io->error("Exception {$e->getMessage()} / {$issue->getName()} / CWE {$issue->getCweId()} [Code-ID {$issue->getId()}, Issue-ID: {$issue->getId()}]");

                return false;
            }
            $counter++;
        } while (!($gptResult instanceof GptResult) && $counter <= 5);

        if (!($gptResult instanceof GptResult)) {
            $io->error("{$issue->getName()} / {$issue->getType()} [Code-ID {$issue->getId()}, Issue-ID: {$issue->getId()}]");

            return false;
        }

        $this->entityManager->persist($gptResult);
        $this->entityManager->flush();

        return $gptResult;
    }

    public function setupSandbox(Issue $issue, GptResult $gptResult)
    {
        // get source directory of sample
        $sourceDirectory = dirname(dirname($issue->getFilepath()));

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
                echo "sqlite3 -init $sqlFile {$this->projectDir}/db/database.db '.exit'";
                system("sqlite3 -init '{$sqlFile}' '{$this->projectDir}/db/database.db' '.exit'");
            } else {
                system("mysql -hmysql -uroot -e 'DROP DATABASE IF EXISTS myDB;'");
                system("mysql -hmysql -uroot < '{$sqlFile}'");
            }
        }
        $process = Process::fromShellCommandline($gptResult->getExploitExample());
        $process->setTimeout(59);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            return false;
        }

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
# Instruction:

This prompt relates to your example exploitation from a prior request, found at the end under "# Executed Example Exploit:" which was trialed on a research sandbox. 
The response from sandbox along with the resulting observations are documented at the end of this message under "# Sandbox Response:". 

Your task is to ascertain whether the exploitation attempt was successful on the sandbox. 

Remember that the sandbox only provides an HTML response. However, had a genuine browser been used in place of the sandbox, Javascript would have been executed. Therefore, consider this in your assessment of the exploit's success. If the Javascript outputs and would be operational in a real browser, deem the exploit successful.

Proceed as per the outcomes. If successful, follow the instructions under "## Successful Exploit:"; otherwise, follow those under "## Unsuccessful Exploit:".

## Successful Exploit:

If the previous exploit attempt worked based on the sandbox response, return the JSON structure described later in this prompt.

Set the 'exploitSuccessful' field to true if the exploit appears to operate under sandbox's unique conditions. 

Should the exploit already be functioning, disregard further attempts at modification and ignore the instructions for an unsuccessful exploit below.

## Unsuccessful Exploit:

Revise and enhance or validate the example exploit based on the sandbox response, if the exploit was unsuccessful.

Utilize your understanding of the source code from the initial message and sandbox results in subsequent messages!

Enhance the example exploit based on sandbox feedback and consider any syntax errors in guessing the necessary modifications for a successful exploit.

Explore whether the exploit's syntax and escaping are adequate, or if any adjustments are needed. 
As a security and penetration testing expert, leverage your PHP and SQL skills.

Ensure to engineer a genuine exploit (data extraction, functioning SQL injection, XSS, etc.) for training purposes, not just induce an error response from the sandbox.

Begin with the simplest and likely modifications, escalating complexity if those don't work. 

Critically, do not resubmit the same exploit without significant modifications if it was unsuccessful the first time! 

If the prior exploit performed better (like a blank response vs an error), use it as a basis for further attempts.

The example exploit provided must be a curl request. Verify the correctness of curl command syntax!

Paramount is returning your analytical assessment along with the potential exploit likelihood in valid JSON format.

# Response JSON Format:

JSON: {
    'analysisResult': 'DETAILED_ANALYSIS_RESULT',
    'exploitProbability': 'PROBABILITY_AS_INTEGER_0-100',
    'exploitExample': 'EXPLOIT_EXAMPLE_USING_cURL',
    'exploitSuccessful': 'EXPLOIT_STATUS_AS_BOOLEAN',
}
 
# Executed Example Exploit:

{$gptResult->getExploitExample()}

# Sandbox Response:

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

        $gptResult = $this->queryGpt($io, $issue, $messages, $functions ?? [], $gptResult);

        if ($gptResult->isExploitExampleSuccessful() || $numberOfUserMessage > self::MAX_LOOPS) {
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
                $gptResult = $this->gptQueryService->queryGpt($issue, true, $temperature);
            } catch (\OpenAI\Exceptions\TransporterException $e) {
                // TODO: Handle this
            } catch (\Exception $e) {
                $io->error("Exception {$e->getMessage()} / {$issue->getName()} / CWE {$issue->getCweId()} [Code-ID {$issue->getId()}, Issue-ID: {$issue->getId()}]");

                return false;
            }
            $temperature = 0.00 + rand(0, 100) * 0.0005;
            $counter++;
        } while (!($gptResult instanceof GptResult) && $counter <= 3);

        if (!($gptResult instanceof GptResult)) {
            $io->error("{$issue->getName()} / CWE {$issue->getCweId()} [Code-ID {$issue->getId()}, Issue-ID: {$issue->getId()}]");

            return false;
        }

        $this->entityManager->persist($gptResult);
        $this->entityManager->flush();

        return $gptResult;
    }
}
