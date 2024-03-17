<?php

namespace App\Controller;

use App\Repository\GptResultRepository;
use App\Repository\IssueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class StatisticsController extends AbstractController
{
    #[Route('/statistics', name: 'app_statistics')]
    public function index(IssueRepository $issueRepository, GptResultRepository $gptResultRepository): Response
    {
        $issues = $issueRepository->findBy(['taintId' => '999']);

        $methods = [
            'psalm',
            'snyk',
            'gpt-3.5-turbo_0613',
            'gpt-4-0125-preview',
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
                    case 'gpt-3.5-turbo_0613':
                        $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, 'gpt-3.5-turbo%-0613');
                        $statistics[$method] = $this->getConfusionTable($statistics[$method], $issue->getConfirmedState(), $gptResult->isExploitExampleSuccessful());
                        break;
                    case 'gpt-4-0125-preview':
                        $gptResult = $gptResultRepository->findLastFeedbackGptResultByIssue($issue, 'gpt-4-0125-preview');
                        $statistics[$method] = $this->getConfusionTable($statistics[$method], $issue->getConfirmedState(), $gptResult->isExploitExampleSuccessful());
                        break;
                    default:
                        break;
                }
            }
        }

        foreach ($statistics as $method => $statistic) {
            $statistics[$method] = $this->calculateStatitics($statistic);
        }

        return $this->render('statistics/index.html.twig', [
            'results' => $statistics,
        ]);
    }

    private function getConfusionTable($table, $confirmedState, $state)
    {
        if ($confirmedState === 1) {
            if ($state) {
                $table['truePositive']++;
            } else {
                $table['falsePositives']++;
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

    #https://artemoppermann.com/de/accuracy-precision-recall-f1-score-und-specificity/

    private function calculateStatitics($results)
    {
        $count = array_sum($results);
        $results['count'] = $count;
        $results['sensitivity'] = $results['truePositive'] / 25;
        $results['precision'] = $results['truePositive'] / ($results['truePositive'] + $results['falsePositives']);
        $results['accuracy'] = ($results['truePositive'] + $results['trueNegatives']) / $count;
        $results['specificity'] = $results['trueNegatives'] / ($results['trueNegatives'] + $results['falsePositives']);
        $results['f1'] = 2 * $results['truePositive'] / ( 2 * $results['truePositive'] + $results['falsePositives'] + $results['falseNegatives']);
        return $results;
    }
}
