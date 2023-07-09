<?php

namespace App\Controller;

use App\Repository\CodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/')]
class QueueController extends AbstractController
{
    #[Route('/', name: 'app_queue_index', methods: ['GET'])]
    public function index(CodeRepository $codeRepository): Response
    {
        // TODO: Implement queue view for gpt tasks
        return $this->render('code/index.html.twig', [
            'codes' => $codeRepository->findAll(),
        ]);
    }
}
