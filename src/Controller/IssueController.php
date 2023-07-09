<?php

namespace App\Controller;

use App\Entity\Issue;
use App\Form\Issue1Type;
use App\Repository\IssueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    #[Route('/new', name: 'app_issue_new', methods: ['GET', 'POST'])]
    public function new(Request $request, IssueRepository $issueRepository): Response
    {
        $issue = new Issue();
        $form = $this->createForm(Issue1Type::class, $issue);
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
    public function show(Issue $issue): Response
    {
        return $this->render('issue/show.html.twig', [
            'issue' => $issue,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_issue_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Issue $issue, IssueRepository $issueRepository): Response
    {
        $form = $this->createForm(Issue1Type::class, $issue);
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
        if ($this->isCsrfTokenValid('delete' . $issue->getId(), $request->request->get('_token'))) {
            $issueRepository->remove($issue, true);
        }

        return $this->redirectToRoute('app_issue_index', [], Response::HTTP_SEE_OTHER);
    }
}
