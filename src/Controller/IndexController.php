<?php

namespace App\Controller;

use App\Repository\IssueRepository;
use App\Service\Stats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/')]
class IndexController extends AbstractController
{
    private Stats $stats;

    public function __construct(Stats $stats)
    {
        $this->stats = $stats;
    }

    #[Route('/', name: 'app_index', methods: ['GET'])]
    public function index(IssueRepository $issueRepository): Response
    {
        $issues = $issueRepository->findAll();
        $statistics = $this->stats->getStatistics($issues);

        return $this->render('index.html.twig', [
            'results' => $statistics['statistics'],
        ]);
    }
}
