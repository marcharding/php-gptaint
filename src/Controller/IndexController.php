<?php

namespace App\Controller;

use App\Repository\GptResultRepository;
use App\Repository\IssueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/')]
class IndexController extends AbstractController
{
    #[Route('/', name: 'app_index', methods: ['GET'])]
    public function index(IssueRepository $issueRepository, GptResultRepository $gptResultRepository): Response
    {
        $issues = $issueRepository->findAll();

        $methods = [
            'psalm',
            'snyk',
            'gpt-3.5-turbo-0125',
            'gpt-4-0125-preview',
            'mistral-small-latest',
            'phan',
        ];

        $statistics = [];

        foreach ($issues as $issue) {
            foreach ($methods as $method) {
                if (!isset($statistics[$method])) {
                    $statistics[$method] = [
                        'truePositive' => 0,
                        'trueNegatives' => 0,
                        'falsePositives' => 0,
                        'falseNegatives' => 0,
                    ];
                }
                switch ($method) {
                    case 'psalm':
                        $statistics[$method] = $this->getConfusionTable($statistics[$method], $issue->getConfirmedState(), $issue->getPsalmState());
                        break;
                    case 'snyk':
                        $statistics[$method] = $this->getConfusionTable($statistics[$method], $issue->getConfirmedState(), $issue->getSnykState());
                        break;
                    case 'phan':
                        $statistics[$method] = $this->getConfusionTable($statistics[$method], $issue->getConfirmedState(), $issue->getPhanState());
                        break;
                    case 'gpt-4-0125-preview':
                    case 'gpt-3.5-turbo-0125':
                    case 'mistral-small-latest':
                        $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, $method);
                        if ($gptResult) {
                            $statistics[$method] = $this->getConfusionTable($statistics[$method], $issue->getConfirmedState(), $gptResult->isExploitExampleSuccessful());
                        }
                        break;
                    default:
                        break;
                }
            }
        }

        foreach ($statistics as $method => $statistic) {
            $statistics[$method] = $this->calculateStatitics($statistic);
        }

        return $this->render('index.html.twig', [
            'results' => $statistics,
        ]);
    }

    private function getConfusionTable($table, $confirmedState, $state)
    {
        if ($confirmedState === 1) {
            if ($state) {
                $table['truePositive']++;
            } else {
                $table['falseNegatives']++;
            }
        }

        if ($confirmedState === 0) {
            if (!$state) {
                $table['trueNegatives']++;
            } else {
                $table['falsePositives']++;
            }
        }

        return $table;
    }

    private function calculateStatitics($results)
    {
        $count = array_sum($results);

        $results['sum'] = $count;

        $results['count'] = $count;

        $results['sensitivity'] = $results['truePositive'] != 0 ? $results['truePositive'] / 25 : 0;

        $results['precision'] = ($results['truePositive'] + $results['falsePositives']) != 0 ? $results['truePositive'] / ($results['truePositive'] + $results['falsePositives']) : 0;

        $results['accuracy'] = $count != 0 ? ($results['truePositive'] + $results['trueNegatives']) / $count : 0;

        $results['specificity'] = ($results['trueNegatives'] + $results['falsePositives']) != 0 ? $results['trueNegatives'] / ($results['trueNegatives'] + $results['falsePositives']) : 0;

        $results['f1'] = ($results['truePositive'] + $results['falsePositives'] + $results['falseNegatives']) != 0 ? 2 * $results['truePositive'] / (2 * $results['truePositive'] + $results['falsePositives'] + $results['falseNegatives']) : 0;

        return $results;
    }
}
