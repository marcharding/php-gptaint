<?php

namespace App\Service;

use App\Entity\GptResult;
use App\Entity\Issue;
use App\Entity\Prompt;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Yethee\Tiktoken\EncoderProvider;

class GptQuery
{
    protected EntityManagerInterface $entityManager;

    protected string $projectDir;
    protected string $openAiToken;
    private string $model;

    public function __construct(string $projectDir, string $openAiToken, string $defaultModel, string $mistralAiToken, EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->projectDir = $projectDir;
        $this->model = $defaultModel;
        $this->openAiToken = $openAiToken;
        $this->mistralAiToken = $mistralAiToken;
    }

    public function setModel($model)
    {
        $this->model = $model;
    }
    public function getModel()
    {
        return $this->model;
    }

    public function queryGpt(Issue $issue, $functionCall = true, $temperature = 0.10, $messages = [], $additionalFunctions = [], GptResult $parentGptResult = null): GptResult|array
    {
        $model = $this->model;

        if (str_contains($model, 'mistral')) {
            $tokenToUse = $this->mistralAiToken;
        } else {
            $tokenToUse = $this->openAiToken;
        }

        $openAiClient = \OpenAI::factory()
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 30]))
            ->withApiKey($tokenToUse);

        if (str_contains($model, 'llama.cpp')) {
            $openAiClient->withBaseUri('http://host.docker.internal:8080/v1');
        }

        if (str_contains($model, 'mistral')) {
            $openAiClient->withBaseUri('https://api.mistral.ai/v1');
        }

        $openAiClient = $openAiClient->make();

        $provider = new EncoderProvider();
        $encoder = $provider->get('cl100k_base');

        $code = $issue->getExtractedCodePath();

        $promptEntity = $this->entityManager->getRepository(Prompt::class)->findOneBy(['type' => 999, 'active' => 1]);

        $promptMessage = $promptEntity->getPrompt();
        $prompt = [
            'temperature' => $temperature,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => "$promptMessage \n \n \n $code",
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
                            'exploitSuccessful' => [
                                'type' => 'boolean',
                                'description' => 'Based on the security analysis: Is the exploit successful or not?',
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

        if (!empty($additionalFunctions)) {
            $prompt['functions'][0]['parameters']['properties'] += $additionalFunctions;
        }

        if (!empty($messages)) {
            $prompt['messages'] = $messages;
            $promptMessage = end($messages)['content'];
        }

        if ($functionCall === false) {
            unset($prompt['functions']);
            unset($prompt['function_call']);
        }

        $numberOfTokens = $issue->getEstimatedTokens() + count($encoder->encode(json_encode($prompt['messages'])));
        if (isset($prompt['functions'])) {
            $numberOfTokens += count($encoder->encode(json_encode($prompt['functions'])));
        }

        if (str_contains($model, 'llama.cpp')) {
            $prompt['grammar'] = file_get_contents("{$this->projectDir}/config/grammar/jsonStructure.gbnf");
            unset($prompt['functions']);
            unset($prompt['function_call']);
            // prevent endless generation, see https://github.com/ggerganov/llama.cpp/pull/6638
            $prompt['n_predict'] = 2048;
        }

        if (str_contains($model, 'mistral')) {
            $prompt['response_format'] = ['type' => 'json_object'];
            unset($prompt['functions']);
            unset($prompt['function_call']);
        }

        $model = explode('/', $model);
        $model = end($model);

        $prompt['model'] = $model;

        $stopwatch = new Stopwatch();
        $stopwatch->start('query');
        $response = $openAiClient->chat()->create($prompt);
        $event = $stopwatch->stop('query');
        $result = $response->choices[0];
        $duration = $event->getDuration();

        $json = false;

        if (isset($result->message->functionCall)) {
            $jsonString = $result->message->functionCall->arguments;

            // TODO: Is this the best we can do?
            $json = json_decode($jsonString, true);

            if ($json === null) {
                $pattern = '#"analysisResult":\s*"(?P<analysisResult>.*?)",\s*"exploitProbability":\s*(?P<exploitProbability>\d+),\s*"exploitExample":\s*"(?P<exploitExample>.*?)"(,\s*"exploitSuccessful":\s*"(?P<exploitSuccessful>.*?)")?#ism';
                $matches = [];
                if (preg_match($pattern, $jsonString, $matches)) {
                    $json = [
                        'analysisResult' => $matches['analysisResult'],
                        'exploitProbability' => (int) $matches['exploitProbability'],
                        'exploitExample' => $matches['exploitExample'] ?? 'Could not get exploit example',
                        'exploitSuccessful' => $matches['exploitSuccessful'] ?? false,
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
            $exploitSuccessful = $json['exploitSuccessful'] ?? false;
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
                $exploitSuccessful = $json['exploitSuccessful'] ?? false;
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

        if (empty($json)) {
            $json = json_decode(str_replace(",\n", ',', $result->message->content), true);
            if ($json !== false) {
                $analysisResult = $json['analysisResult'] ?? 'Could not get analysis result';
                $exploitProbability = $json['exploitProbability'] ?? null;
                $exploitExample = $json['exploitExample'] ?? 'Could not get exploit example';
                $exploitSuccessful = $json['exploitSuccessful'] ?? false;
            }
        }

        if (!isset($completeResult) || !isset($analysisResult) || !isset($exploitProbability) || !is_numeric($exploitProbability) || !isset($exploitExample)) {
            // TODO: Better error handling
            return [['completeResult' => $completeResult, 'analysisResult' => $analysisResult, 'exploitProbability' => $exploitProbability, 'exploitExample' => $exploitExample], $response->toArray()];
        }

        $gptResult = new GptResult();
        $gptResult->setIssue($issue);
        $gptResult->setGptVersion($model);
        $gptResult->setPrompt($promptEntity);
        $gptResult->setPromptMessage($promptMessage);
        $gptResult->setResponse($completeResult);
        $gptResult->setAnalysisResult($analysisResult);
        $gptResult->setExploitProbability($exploitProbability);
        $gptResult->setExploitExample($exploitExample);
        $gptResult->setExploitExampleSuccessful(filter_var($exploitSuccessful ?? false, FILTER_VALIDATE_BOOLEAN));
        $gptResult->setTime($duration);
        $gptResult->setPromptTokens($response['usage']['prompt_tokens']);
        $gptResult->setCompletionTokens($response['usage']['completion_tokens']);
        if ($parentGptResult) {
            $gptResult->setGptResultParent($parentGptResult);
        }

        return $gptResult;
    }

    public function faultTolerantFunctionCallResultParser($string, $prompt)
    {
        $keys = array_keys($prompt['functions'][0]['parameters']['properties']);
        $keys =
      [
          'analysisResult',
          'exploitProbability',
          'exploitExample',
      ];
        $regexParts = [];
        foreach ($keys as $key) {
            //            $pattern = '/(?:,\s*)?"' . $key . '"\s*:\s*(?:"(?P<theResult>[^"]*)"|(?P<theResult>\S+))/';
            //            $pattern = '/(?:,\s*)?"%s"\s*:\s*(?:"(?P<%s>[^"]*)"|(?P<%s>\S+))/';
            $pattern = sprintf('\s*("|\')?%s*("|\')?:\s*(")?(?P<%s>.*?)(")', $key, $key);
            $regexParts[] = $pattern;
        }
        $jsonString = <<<EOT
        {
  "analysisResult": "The SQL injection vulnerability in the code has been confirmed. The exploit example successfully triggered a SQL syntax error in the sandbox environment. The error message indicates that the input '1'='1' was injected into the SQL query, causing a syntax error. This confirms that the vulnerability can be exploited.",\n
  "exploitProbability": 100,
  "exploitExample": curl -X POST -d \"t=' OR '1'='1\" http://example.com"
}
EOT;
        dump('#'.implode('', $regexParts).'#ism');
        $pattern = '#\s*"analysisResult":\s*(")?(?P<analysisResult>.*?)("|,)\s*"exploitProbability":\s*(?P<exploitProbability>\d+),\s*"exploitExample":\s*"(?P<exploitExample>.*?)"(,\s*"exploitSuccessful":\s*"(?P<exploitSuccessful>.*?)")?#ism';
        $pattern = '#'.implode('', $regexParts).'#ism';

        if (preg_match($pattern, $jsonString, $matches)) {
            dump($matches);
        }

        dump($regexParts);
        dump($keys);

        $pattern = '/"([^"]+)":\s*("[^"]+"|[^,}]+)\s*(?:,|\})/';

        $pattern = '/"([^"]+)":\s*(".*?"|[^,}]+)\s*(?:,|\})/';

        preg_match_all($pattern, $jsonString, $matches, PREG_SET_ORDER);

        $result = [];
        foreach ($matches as $match) {
            $key = $match[1];
            $value = json_decode($match[2], true);
            $result[$key] = $value;
        }

        return $result;
    }
}
