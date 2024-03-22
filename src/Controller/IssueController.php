<?php

namespace App\Controller;

use App\Entity\Issue;
use App\Repository\IssueRepository;
use App\Service\TaintTypes;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Routing\Annotation\Route;

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
