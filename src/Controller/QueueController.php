<?php

namespace App\Controller;

use App\Repository\CodeRepository;
use App\Repository\IssueRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/queue')]
class QueueController extends AbstractController
{
    #[Route('/', name: 'app_queue_index', methods: ['GET'])]
    public function index(CodeRepository $codeRepository, ManagerRegistry $managerRegistry, IssueRepository $issueRepository): Response
    {
        $phpSerializer = new PhpSerializer;
        $connection = $managerRegistry->getConnection();
        $result = $connection
            ->prepare("SELECT * FROM messenger_messages ORDER BY created_at")
            ->executeQuery()
            ->fetchAllAssociative();

        $result = array_map(function ($item) use ($phpSerializer, $issueRepository) {
            $item['message'] = $phpSerializer->decode($item)->getMessage();
            $item['issue'] = $issueRepository->find($item['message']->getId());
            return $item;
        }, $result);

        // TODO: Implement queue view for gpt tasks
        return $this->render('queue/index.html.twig', [
            'messages' => $result,
        ]);
    }
}
