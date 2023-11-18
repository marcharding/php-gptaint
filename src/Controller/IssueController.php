<?php

namespace App\Controller;

use App\Entity\Issue;
use App\Form\IssueType;
use App\Repository\GptResultRepository;
use App\Repository\IssueRepository;
use App\Service\CodeExtractor\CodeExtractor;
use App\Service\SarifToFlatArrayConverter;
use App\Service\TaintTypes;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Routing\Annotation\Route;
use Yethee\Tiktoken\EncoderProvider;

#[Route('/issue')]
class IssueController extends AbstractController
{
    #[Route('/', name: 'app_issue_index', methods: ['GET'])]
    public function index(IssueRepository $issueRepository): Response
    {
        return $this->render('issue/index.html.twig', [
            'issues' => $issueRepository->findAll(),
        ]);
    }

    #[Route('/issue_type_list', name: 'issue_types_list')]
    public function list(IssueRepository $issueRepository): Response
    {
        $issueTypes = $issueRepository->findDistinctIssueTypes();

        return $this->render('issue/issue_group.html.twig', [
            'issueTypes' => $issueTypes,
        ]);
    }

    #[Route('/issue_type/{issueType}', name: 'issue_list_by_type')]
    public function listByType(string $issueType, IssueRepository $issueRepository): Response
    {
        $issues = $issueRepository->findByIssueType($issueType);

        return $this->render('issue/issue_by_type.html.twig', [
            'issues' => $issues,
        ]);
    }

    #[Route('/issue_with_gpt_result', name: 'issue_with_gpt_result')]
    public function listWithGptResultType(IssueRepository $issueRepository): Response
    {
        $issues = $issueRepository->findAllWithGptResult();

        return $this->render('issue/issues_with_gpt_result.html.twig', [
            'issues' => $issues,
        ]);
    }

    #[Route('/new', name: 'app_issue_new', methods: ['GET', 'POST'])]
    public function new(Request $request, IssueRepository $issueRepository): Response
    {
        $issue = new Issue();
        $form = $this->createForm(IssueType::class, $issue);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $issueRepository->save($issue, true);

            return $this->redirectToRoute('app_issue_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('issue/new.html.twig', [
            'issue' => $issue,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_issue_show', methods: ['GET'])]
    public function show(Issue $issue, ManagerRegistry $managerRegistry): Response
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

        return $this->render('issue/show.html.twig', [
            'queue' => $result,
            'issue' => $issue,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_issue_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Issue $issue, IssueRepository $issueRepository): Response
    {
        $form = $this->createForm(IssueType::class, $issue);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $issueRepository->save($issue, true);

            return $this->redirectToRoute('app_issue_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('issue/edit.html.twig', [
            'issue' => $issue,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_issue_delete', methods: ['POST'])]
    public function delete(Request $request, Issue $issue, IssueRepository $issueRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$issue->getId(), $request->request->get('_token'))) {
            $issueRepository->remove($issue, true);
        }

        return $this->redirectToRoute('app_issue_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/extract-code', name: 'app_issue_extract_code', methods: ['GET'])]
    public function extractCode(Issue $issue, ManagerRegistry $managerRegistry, $projectDir): Response
    {
        $codeExtractor = new CodeExtractor();
        $folderName = $issue->getCode()->getDirectory();

        $provider = new EncoderProvider();
        $encoder = $provider->get('cl100k_base');

        $s = new SarifToFlatArrayConverter();
        $psalmResultFile = $projectDir.DIRECTORY_SEPARATOR.'data/wordpress/sarif'.DIRECTORY_SEPARATOR.$folderName.'.sarif';
        $psalmResultFile = json_decode(file_get_contents($psalmResultFile), true);
        $sarifResults = $s->getArray($psalmResultFile);

        // store optimized codepath and unoptimized for token comparison / effectiveness
        $extractedCodePath = '';
        $unoptimizedCodePath = '';
        $entry = $sarifResults[$issue->getTaintId().'_'.$issue->getFile()];
        foreach ($entry['locations'] as $item) {
            $pluginRoot = $projectDir.DIRECTORY_SEPARATOR.'data/wordpress/plugins_tainted'.DIRECTORY_SEPARATOR.$folderName.DIRECTORY_SEPARATOR;
            $extractedCodePath .= "// @FILE: /wp-content/plugins/{$folderName}/{$item['file']}".PHP_EOL.PHP_EOL.PHP_EOL;
            $extractedCodePath .= $codeExtractor->extractCodeLeadingToLine($pluginRoot.$item['file'], $item['region']['startLine']);
            $extractedCodePath .= PHP_EOL.PHP_EOL.PHP_EOL;
            $unoptimizedCodePath .= file_get_contents($pluginRoot.$item['file']);
        }

        $issue->setExtractedCodePath($extractedCodePath);
        $issue->setEstimatedTokens(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $extractedCodePath))));
        $issue->setEstimatedTokensUnoptimized(count($encoder->encode(iconv('UTF-8', 'UTF-8//IGNORE', $unoptimizedCodePath))));

        $managerRegistry->getManager()->persist($issue);
        $managerRegistry->getManager()->flush();

        return $this->redirectToRoute('app_issue_show', ['id' => $issue->getId()]);
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
