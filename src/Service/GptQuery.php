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
    private string $randomize;

    public function __construct(string $projectDir, string $openAiToken, string $defaultModel, string $mistralAiToken, EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->projectDir = $projectDir;
        $this->model = $defaultModel;
        $this->openAiToken = $openAiToken;
        $this->mistralAiToken = $mistralAiToken;
        $this->randomize = false;
    }

    public function setModel($model)
    {
        $this->model = $model;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function setRandomize($randomize)
    {
        $this->randomize = $randomize;
    }

    public function isRandomized()
    {
        return $this->randomize;
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
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 360, 'connect_timeout' => 30]))
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

        if ($this->isRandomized()) {
            $code = $issue->getCodeRandomized();
        } else {
            $code = $issue->getCode();
        }

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
                            'exploitExample' => [
                                'type' => 'string',
                                'description' => 'Example of a potential attack as a bash script or curl command for educational purposes. Important, just return the script, nothing else, no further explanation or description.',
                            ],
                            'exploitSuccessful' => [
                                'type' => 'boolean',
                                'description' => 'Based on the security analysis: Is the exploit successful or not?',
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

        $functions = $prompt['functions'];
        if ($functionCall === false) {
            unset($prompt['functions']);
            unset($prompt['function_call']);
        }

        $numberOfTokens = $issue->getEstimatedTokens() + count($encoder->encode(json_encode($prompt['messages'])));
        if (isset($prompt['functions'])) {
            $numberOfTokens += count($encoder->encode(json_encode($prompt['functions'])));
        }

        if (str_contains($model, 'llama.cpp')) {
            // apply grammar to get the model to return json
            // see https://github.com/ggerganov/llama.cpp/blob/master/grammars/README.md and https://mychen76.medium.com/practical-techniques-to-constraint-llm-output-in-json-format-e3e72396c670
            // simple conversion with this online tool at https://grammar.intrinsiclabs.ai/.
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

        $modeName = $model;
        if ($this->isRandomized()) {
            $modeName .= ' (randomized)';
        }

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

            // fix prompt formats / tokens (remove residuals like <|eot_id|> from the retrieved json)
            $jsonString = preg_replace("#<\|[a-z_]+\|>#ism", '', $jsonString);

            $json = json_decode($jsonString, true);
            $analysisResult = $json['analysisResult'] ?? 'Could not get analysis result';
            $exploitProbability = $json['exploitProbability'] ?? null;

            // TODO: Sometime only some values are not set in the json response, try to get them via regex
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

        // try to parse message content as json (e.g. llama.ccp grammar or other models that return just json)
        if (empty($json)) {
            $jsonString = $result->message->content;
            $jsonString = preg_replace("#<\|[a-z_]+\|>#ism", '', $jsonString);
            $json = json_decode($jsonString, true);
            if ($json !== false) {
                $analysisResult = $json['analysisResult'] ?? 'Could not get analysis result';
                $exploitProbability = $json['exploitProbability'] ?? null;
                $exploitExample = $json['exploitExample'] ?? 'Could not get exploit example';
                $exploitSuccessful = $json['exploitSuccessful'] ?? false;
                $completeResult = $json['analysisResult'] ?? 'Could not get analysis result';
            }
        }

        if (empty($json)) {
            $json = $this->faultTolerantFunctionCallResultParser($result->message->content, $functions);
            if ($json !== false) {
                $analysisResult = $json['analysisResult'] ?? 'Could not get analysis result';
                $exploitProbability = $json['exploitProbability'] ?? null;
                $exploitExample = $json['exploitExample'] ?? 'Could not get exploit example';
                $exploitSuccessful = $json['exploitSuccessful'] ?? false;
                $completeResult = $json['analysisResult'] ?? 'Could not get analysis result';
            }
        }

        if (!isset($completeResult) || !isset($analysisResult) || !isset($exploitProbability) || !is_numeric($exploitProbability) || !isset($exploitExample)) {
            // TODO: Better error handling
            return [['completeResult' => $completeResult, 'analysisResult' => $analysisResult, 'exploitProbability' => $exploitProbability, 'exploitExample' => $exploitExample], $response->toArray()];
        }

        $gptResult = new GptResult();
        $gptResult->setIssue($issue);
        $gptResult->setGptVersion($modeName);
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
        $keys = array_keys($prompt[0]['parameters']['properties']);

        // fix prompt formats / tokens (remove residuals like <|eot_id|> from the retrieved json)
        $string = preg_replace("#<\|[a-z_]+\|>#ism", '', $string);

        $result = [];

        foreach ($keys as $index => $key) {
            $quotedKey = preg_quote($key);
            $pattern = "#.*?[,]?[\"']{$quotedKey}[\"'].*?[:]?[\s*]?(?P<value>.*?)";

            if ($index < count($keys) - 1) {
                $quotedKey = preg_quote($keys[$index + 1]);
                $pattern .= "[\"']?{$quotedKey}#ism";
            } else {
                $pattern .= '[}]?$#ism';
            }

            preg_match($pattern, $string, $matches);

            if (isset($matches['value'])) {
                $value = $matches['value'];
                $value = explode("\n", $value);
                $value = reset($value);
                $value = rtrim($value, "\n");
                $value = rtrim($value, ' ,');
                // remove quotes from start/end if they are a matching pair (residuals of the json encoding)
                if (preg_match('/^([\'"])(.*?)\1$/', $value, $quoteMatches)) {
                    $value = $quoteMatches[2];
                }
                $result[$key] = trim($value);
            }
        }

        return $result;
    }
}
