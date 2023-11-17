<?php

namespace App\Controller;

use App\Entity\Prompt;
use App\Form\PromptType;
use App\Repository\PromptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/prompt')]
class PromptController extends AbstractController
{
    #[Route('/', name: 'app_prompt_index', methods: ['GET'])]
    public function index(PromptRepository $promptRepository): Response
    {
        return $this->render('prompt/index.html.twig', [
            'prompts' => $promptRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_prompt_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $prompt = new Prompt();
        $form = $this->createForm(PromptType::class, $prompt);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($prompt->isActive()) {
                $allPrompts = $entityManager->getRepository(Prompt::class)->findByTypeIdNotEqual($prompt->getId(), $prompt->getType());
                array_map(function ($prompt) use ($entityManager) {
                    $prompt->setActive(false);
                    $entityManager->persist($prompt);
                }, $allPrompts);
            }
            $entityManager->persist($prompt);
            $entityManager->flush();

            return $this->redirectToRoute('app_prompt_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('prompt/new.html.twig', [
            'prompt' => $prompt,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_prompt_show', methods: ['GET'])]
    public function show(Prompt $prompt): Response
    {
        return $this->render('prompt/show.html.twig', [
            'prompt' => $prompt,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_prompt_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Prompt $prompt, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PromptType::class, $prompt);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($prompt->isActive()) {
                $allPrompts = $entityManager->getRepository(Prompt::class)->findByTypeIdNotEqual($prompt->getId(), $prompt->getType());
                array_map(function ($prompt) use ($entityManager) {
                    $prompt->setActive(false);
                    $entityManager->persist($prompt);
                }, $allPrompts);
            }

            $entityManager->flush();

            return $this->redirectToRoute('app_prompt_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('prompt/edit.html.twig', [
            'prompt' => $prompt,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_prompt_delete', methods: ['POST'])]
    public function delete(Request $request, Prompt $prompt, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$prompt->getId(), $request->request->get('_token'))) {
            $entityManager->remove($prompt);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_prompt_index', [], Response::HTTP_SEE_OTHER);
    }
}
