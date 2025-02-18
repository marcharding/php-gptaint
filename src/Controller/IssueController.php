<?php

namespace App\Controller;

use App\Entity\AnalysisResult;
use App\Entity\Sample;
use App\Repository\GptResultRepository;
use App\Repository\IssueRepository;
use App\Service\TaintTypes;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/issue')]
class IssueController extends AbstractController
{
    #[Route('/', name: 'app_issue_index', methods: ['GET'])]
    public function index(IssueRepository $issueRepository, EntityManagerInterface $entityManager): Response
    {
        $analyzers = $entityManager->getConnection()
            ->executeQuery('SELECT DISTINCT analyzer FROM analysis_result')
            ->fetchAllAssociative();

        return $this->render('issue/index.html.twig', [
            'issues' => $issueRepository->findAll(),
            'analyzers' => $analyzers,
            'analyzer' => null,
            'type' => null,
        ]);
    }

    #[Route('/filtered', name: 'app_issue_index_filtered', methods: ['GET'])]
    public function indexFiltered(IssueRepository $issueRepository, GptResultRepository $gptResultRepository, EntityManagerInterface $entityManager, Request $request): Response
    {
        $analyzer = $request->query->get('analyzer');
        $type = $request->query->get('type');

        $analyzers = $entityManager->getConnection()
            ->executeQuery('SELECT DISTINCT analyzer FROM analysis_result')
            ->fetchAllAssociative();

        $issues = $issueRepository->findAll();
        $filtered = [];
        foreach ($issues as $issue) {
            $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, $analyzer);
            if (!$gptResult) {
                continue;
            }

            $meetsCriteria = false;

            if ($type === 'tp' && $issue->getConfirmedState() == 1 && $gptResult->getResultState() == 1) {
                $meetsCriteria = true;
            } elseif ($type === 'fp' && $issue->getConfirmedState() == 0 && $gptResult->getResultState() == 1) {
                $meetsCriteria = true;
            } elseif ($type === 'tn' && $issue->getConfirmedState() == 0 && $gptResult->getResultState() == 0) {
                $meetsCriteria = true;
            } elseif ($type === 'fn' && $issue->getConfirmedState() == 1 && $gptResult->getResultState() == 0) {
                $meetsCriteria = true;
            } elseif ($type === null) {
                $meetsCriteria = true;
            }

            if ($meetsCriteria === false) {
                continue;
            }

            $filtered[] = $issue;
        }

        return $this->render('issue/index.html.twig', [
            'issues' => $filtered,
            'analyzers' => $analyzers,
            'analyzer' => $analyzer,
            'type' => $type,
        ]);
    }

    #[Route('/{id}', name: 'app_issue_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Sample $issue, ManagerRegistry $managerRegistry, GptResultRepository $gptResultRepository): Response
    {
        $phpSerializer = new PhpSerializer();
        $connection = $managerRegistry->getConnection();
        $result = $connection
            ->prepare('SELECT * FROM messenger_messages ORDER BY created_at')
            ->executeQuery()
            ->fetchAllAssociative();

        $position = 0;
        $result = array_filter(
            array_map(function ($item) use ($phpSerializer, $issue, &$position) {
                $item['message'] = $phpSerializer->decode($item)->getMessage();
                $item['position'] = $position;
                $position++;
                if ($item['message']->getId() === $issue->getId()) {
                    return $item;
                }
            }, $result));

        $resultsByAnalyzer = [];
        foreach ($issue->getAnalyzerResultsGroupedByAnalyzer() as $analyzer => $groupedResult) {
            $resultsByAnalyzer[$analyzer][] = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, $analyzer);
        }

        return $this->render('issue/show.html.twig', [
            'queue' => $result,
            'issue' => $issue,
            'resultsByAnalyzer' => $resultsByAnalyzer,
        ]);
    }

    #[Route('/{id}/init-sandbox/{gptResult}', name: 'app_issue_init_sandbox', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function initSandbox(Sample $issue, AnalysisResult $gptResult, string $projectDir): Response
    {
        // get source directory of sample
        $sourceDirectory = $projectDir.dirname(dirname($issue->getFilepath()));

        // find sample files (named index.php or sample.php first, than any php file (legacy format with CWE_* names)
        $finder = new Finder();
        $sourceFiles = $finder->in($sourceDirectory)->files()->name(['sample.php', 'index.php', 'CWE_*.php'])->getIterator();
        $sourceFiles->rewind();
        $sourceFile = $sourceFiles->current()->getRealPath();
        $targetFile = "{$projectDir}/sandbox/public/index.php";

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
                if (is_file("{$projectDir}/db/database.db")) {
                    unlink("{$projectDir}/db/database.db");
                }
                echo "sqlite3 -init $sqlFile {$projectDir}/db/database.db '.exit'";
                system("sqlite3 -init '{$sqlFile}' '{projectDir}/db/database.db' '.exit'");
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
        } finally {
            return $this->render('issue/result.html.twig', [
                'issue' => $issue,
                'proccess' => $process,
            ]);
        }
    }

    public function getPsalmResultsArray($projectDir, $folderName): array
    {
        $psalmResultFile = $projectDir.DIRECTORY_SEPARATOR.'data/wordpress/results'.DIRECTORY_SEPARATOR.$folderName.'.txt';
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
