<?php

namespace App\Controller;

use App\Message\GptMessage;
use App\Message\GptMessageSync;
use App\Repository\IssueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/gpt_add_to_queue')]
class GptController extends AbstractController
{
    #[Route('/{id}', name: 'gpt_add_to_queue', methods: ['GET'])]
    public function index(Request $request, IssueRepository $issueRepository, MessageBusInterface $bus, $id): Response
    {
        $issue = $issueRepository->findOneBy(['id' => $id]);

        $bus->dispatch(new GptMessage($issue->getId()));

        return $this->redirectToRoute('app_issue_show', ['id' => $id]);
    }

    #[Route('/{id}', name: 'gpt_add_to_queue_sync', methods: ['GET'])]
    public function index_sync(Request $request, IssueRepository $issueRepository, MessageBusInterface $bus, $id): Response
    {
        $issue = $issueRepository->findOneBy(['id' => $id]);

        $bus->dispatch(new GptMessageSync($issue->getId()));

        return $this->redirectToRoute('app_issue_show', ['id' => $id]);
    }
}
