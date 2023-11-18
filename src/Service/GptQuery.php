<?php

namespace App\Service;

use App\Entity\GptResult;
use App\Entity\Issue;
use App\Entity\Prompt;
use Doctrine\ORM\EntityManagerInterface;
use OpenAI;
use Yethee\Tiktoken\EncoderProvider;

class GptQuery
{
    protected EntityManagerInterface $entityManager;
    protected OpenAI\Client $openAiClient;

    protected string $projectDir;

    public function __construct(string $projectDir, $openAiToken, EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->projectDir = $projectDir;
        $this->openAiClient = \OpenAI::client($openAiToken);
        $this->openAiClient = \OpenAI::factory()
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 30]))
            ->withApiKey($openAiToken)
            ->make();
    }

    public function queryGpt(Issue $issue, $functionCall = true, $temperature = 0.00, $modelToUse = 'gpt-3.5-turbo-0613'): GptResult|array|false
    {
        $provider = new EncoderProvider();
        $encoder = $provider->get('cl100k_base');

        $code = $issue->getExtractedCodePath();

        $promptEntity = $this->entityManager->getRepository(Prompt::class)->findOneBy(['type' => $issue->getCode()->getType(), 'active' => 1]);

        $prompt = [
            'temperature' => $temperature,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "{$promptEntity->getPrompt()} $code",
                ],
            ],
            'functions' => [
                [
                    'name' => 'provide_analysis_result',
                    'description' => 'Provides the result of security analysis as tasked in the prompt of this message.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'analysisResult' => [
                                'type' => 'string',
                                'description' => 'The detailed analysis report as if the function was not called.', //  Make sure the string is json safe and probably escaped.
                            ],
                            'exploitProbability' => [
                                'type' => 'number',
                                'description' => 'The probability of an exploit on a scale of 0 to 100.',
                            ],
                            'exploitExample' => [
                                'type' => 'string',
                                'description' => 'Example of a potential attack as a bash script or curl command for educational purposes. Important, just return the script, nothing else, no further explanation or description.',
                            ],
                        ],
                    ],
                ],
            ],
            'function_call' => ['name' => 'provide_analysis_result'],
        ];

        if ($functionCall === false) {
            unset($prompt['functions']);
            unset($prompt['function_call']);
        }

        $numberOfTokens = $issue->getEstimatedTokens() + count($encoder->encode("$promptEntity->getPrompt() $code"));

        if (isset($prompt['functions'])) {
            $numberOfTokens += count($encoder->encode(json_encode($prompt['functions'])));
        }

        $modelMapping = [
            'gpt-3.5-turbo-0613' => [
                '4k' => 'gpt-3.5-turbo-0613',
                '16k' => 'gpt-3.5-turbo-16k-0613',
            ],
            'gpt-4-1106-preview' => [
                '4k' => 'gpt-4-1106-preview',
                '16k' => 'gpt-4-1106-preview',
            ],
        ];

        if ($numberOfTokens > 16385) {
            return false;
        } elseif ($numberOfTokens > 4096) {
            $model = $modelMapping[$modelToUse]['1k'];
        } else {
            $model = $modelMapping[$modelToUse]['4k'];
        }

        $prompt['model'] = $model;

        $response = $this->openAiClient->chat()->create($prompt);
        $result = $response->choices[0];

        $json = false;

        if (isset($result->message->functionCall)) {
            $jsonString = $result->message->functionCall->arguments;

            // TODO: Is this the best we can do?
            $json = json_decode($jsonString, true);
            if ($json === null) {
                $pattern = '#"analysisResult":\s*"(?P<analysisResult>.*?)",\s*"exploitProbability":\s*(?P<exploitProbability>\d+),\s*"exploitExample":\s*"(?P<exploitExample>.*?)"#ism';
                $matches = [];
                if (preg_match($pattern, $jsonString, $matches)) {
                    $json = [
                        'analysisResult' => $matches['analysisResult'],
                        'exploitProbability' => (int) $matches['exploitProbability'],
                        'exploitExample' => $matches['exploitExample'] ?? 'Could not get exploit example',
                    ];
                }
            }

            $analysisResult = $json['analysisResult'] ?? 'Could not get analysis result';
            $exploitProbability = $json['exploitProbability'] ?? null;

            // TODO: Sometime some values are not set in the json response, try to get them via regex
            // TODO: Refactor this into a better regex and reuseable function

            if (!isset($json['exploitProbability'])) {
                $pattern = '#(?<exploitProbability>\d+)?(%)#ism';
                $matches = [];
                if (preg_match($pattern, $analysisResult, $matches)) {
                    $exploitProbability = intval($matches['exploitProbability']);
                }
            }

            $completeResult = $json['analysisResult'] ?? 'Could not get analysis result';
            $exploitExample = $json['exploitExample'] ?? 'Could not get exploit example';
        }

        if ($json === false) {
            $lines = explode("\n", $result->message->content);
            $lastLine = end($lines);
            $regex = '/(?<!")([a-zA-Z0-9_]+)(?!")(?=:)/i';
            $lastLine = preg_replace($regex, '"$1"', $lastLine);
            $json = json_decode($lastLine, true);
            if ($json !== false) {
                $analysisResult = $json['analysisResult'] ?? 'Could not get analysis result';
                $exploitProbability = $json['exploitProbability'] ?? null;
                $exploitExample = $json['exploitExample'] ?? 'Could not get exploit example';
            }
            $completeResult = $result->message->content;
        }

        if ($json === false) {
            $pattern = '/exploitProbability":\s*"(\d+)"/';
            $matches = [];
            if (preg_match($pattern, $result->message->content, $matches)) {
                $analysisResult = $result->message->content;
                $exploitProbability = intval($matches[1]);
            }
            $completeResult = $result->message->content;
        }

        if (!isset($completeResult) || !isset($analysisResult) || !isset($exploitProbability) || !isset($exploitExample)) {
            // TODO: Better error handling
            return [['completeResult' => $completeResult, 'analysisResult' => $analysisResult, 'exploitProbability' => $exploitProbability, 'exploitExample' => $exploitExample], $response->toArray()];
        }

        $gptResult = new GptResult();
        $gptResult->setIssue($issue);
        $gptResult->setGptVersion($model);
        $gptResult->setPrompt($promptEntity);
        $gptResult->setResponse($completeResult);
        $gptResult->setAnalysisResult($analysisResult);
        $gptResult->setExploitProbability($exploitProbability);
        $gptResult->setExploitExample($exploitExample);

        return $gptResult;
    }
}
