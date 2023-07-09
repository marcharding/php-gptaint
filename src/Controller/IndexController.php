<?php

namespace App\Controller;

use App\Entity\Code;
use App\Form\Code1Type;
use App\Repository\CodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/')]
class IndexController extends AbstractController
{
    #[Route('/', name: 'app_index', methods: ['GET'])]
    public function index(CodeRepository $codeRepository): Response
    {
        return $this->render('code/index.html.twig', [
            'codes' => $codeRepository->findAll(),
        ]);
    }
}
