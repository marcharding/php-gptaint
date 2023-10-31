<?php

namespace App\Service;

use App\Entity\GptResult;
use App\Entity\Issue;
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
        $this->openAiClient = OpenAI::client($openAiToken);
        $this->openAiClient =  OpenAI::factory()
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 30]))
            ->withApiKey($openAiToken)
            ->make();
    }

    public function queryGpt(Issue $issue, $functionCall = true): GptResult
    {
        $provider = new EncoderProvider();
        $encoder = $provider->get('cl100k_base');

        $code = $issue->getExtractedCodePath();

        $userPrompt = file_get_contents($this->projectDir . '/prompt/0011.md');

        $prompt = [
            'temperature' => 0.00 + rand(0, 100) * 0.0005, // TODO: Is this a good idea?
            'temperature' => 0.00, // TODO: Is this a good idea?
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "$userPrompt $code",
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
                                'description' => 'The detailed analysis report as if the function was not called.',
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
                ]
            ],
            'function_call' => ['name' => 'provide_analysis_result']
        ];

        if ($functionCall === false) {
            unset($prompt['functions']);
            unset($prompt['function_call']);
        }

        $numberOfTokens = $issue->getEstimatedTokens() + count($encoder->encode("$userPrompt $code"));

        if (isset($prompt['functions'])) {
            $numberOfTokens += count($encoder->encode(json_encode($prompt['functions'])));
        }

        if ($numberOfTokens > 4096) {
            $model = 'gpt-3.5-turbo-16k-0613';
        } else {
            $model = 'gpt-3.5-turbo-0613';
        }

        $prompt['model'] = $model;

        $response = $this->openAiClient->chat()->create($prompt);
        $result = $response->choices[0];

        $json = false;

        if (isset($result->message->functionCall)) {
            $json = $result->message->functionCall->arguments;
            $json = json_decode($json, true);
            $analysisResult = $json['analysisResult'];
            $exploitProbability = $json['exploitProbability'];
            $completeResult = $json['analysisResult'];
            $exploitExample = $json['exploitExample'];
        }

        if ($json === false) {
            $lines = explode("\n", $result->message->content);
            $lastLine = end($lines);
            $regex = '/(?<!")([a-zA-Z0-9_]+)(?!")(?=:)/i';
            $lastLine = preg_replace($regex, '"$1"', $lastLine);
            $json = json_decode($lastLine, true);
            if ($json !== false) {
                $analysisResult = $json['analysisResult'];
                $exploitProbability = $json['exploitProbability'];
                $exploitExample = $json['exploitExample'];
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

        if(!isset($completeResult) || !isset($analysisResult) || !isset($exploitProbability) || !isset($exploitExample)){
            // TODO: Add error handing, restart the process with higher temperature or maybe not as function call
        }

        $gptResult = new GptResult();
        $gptResult->setIssue($issue);
        $gptResult->setGptVersion($model);
        $gptResult->setResponse($completeResult);
        $gptResult->setAnalysisResult($analysisResult);
        $gptResult->setExploitProbability($exploitProbability);
        $gptResult->setExploitExample($exploitExample);

        return $gptResult;
    }
}
