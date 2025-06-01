<?php

namespace Pipeline;

use Utils\Logger;
use Utils\SocketNotifier;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Utils\FileHelper;

class AiTestGenerator
{
    private Client $client;

    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier
    ) {
        $this->client = new Client([
            'timeout' => (int)($_ENV['MODEL_TIMEOUT'] ?? 30)
        ]);
    }

    public function generate(array $vulnerabilities, array $escapedMutants): array
    {
        $this->logger->info("Starting test generation", [
            'vulnerabilities' => count($vulnerabilities),
            'escapedMutants' => count($escapedMutants)
        ]);
        $this->notifier->sendUpdate("Starting test generation", 80);

        $testCases = [];

        // // Generate tests for vulnerabilities
        // foreach ($vulnerabilities as $file => $fileVulnerabilities) {
        //     foreach ($fileVulnerabilities as $vulnerability) {
        //         $testCases[] = $this->generateTestCase($file, $vulnerability);
        //     }
        // }

        // Generate tests for escaped mutants
        foreach ($escapedMutants as $key => $mutant) {
            $this->logger->info("Generating test case for mutant-" . $key, $mutant);
            $testCases[] = $this->generateMutationTestCase($mutant);
        }

        $this->notifier->sendUpdate("Test generation completed", 90);

        return array_filter($testCases); // Remove any null results
    }

    private function generateTestCase(string $file, array $vulnerability): ?array
    {
        try {
            $prompt = $this->buildVulnerabilityPrompt($file, $vulnerability);
            $responses = $this->getResponsesFromLLMs($prompt);

            return [
                'type' => 'vulnerability',
                'file' => $file,
                'vulnerability' => $vulnerability,
                'testCases' => $responses
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to generate test case", [
                'file' => $file,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function generateMutationTestCase(array $mutant): ?array
    {
        try {
            $prompt = $this->buildMutationPrompt($mutant);
            $responses = $this->getResponsesFromLLMs($prompt);

            return [
                'type' => 'mutation',
                'mutant' => $mutant,
                'testCases' => $responses
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to generate mutation test case", [
                'mutant' => $mutant,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function buildVulnerabilityPrompt(string $file, array $vulnerability): string
    {
        return <<<EOT
Generate a PHPUnit test case for the following potential security vulnerability:

File: {$file}
Line: {$vulnerability['line']}
Rule ID: {$vulnerability['ruleId']}
Description: {$vulnerability['description']}

Relevant code:
{$vulnerability['sourceCode']}

Requirements:
1. The test should verify that the code is vulnerable to path traversal
2. Include both positive and negative test cases
3. Use realistic input data
4. Follow PHPUnit best practices
5. Include proper assertions
EOT;
    }

    private function buildMutationPrompt(array $mutant): string
    {
        return <<<EOT
Generate a PHPUnit test case to detect the following code mutation:

File: {$mutant['file']}
Line: {$mutant['line']}
Mutator: {$mutant['mutator']}

Original code:
{$mutant['originalSourceCode']}

Mutated version:
{$mutant['mutatedSourceCode']}

Requirements:
1. The test should fail for the mutated version but pass for the original code
2. Include edge cases
3. Follow PHPUnit best practices
4. Include proper assertions
EOT;
    }

    private function getResponsesFromLLMs(string $prompt): array
    {
        $responses = [];

        // OpenAI GPT
        if (!empty($_ENV['OPENAI_API_KEY'])) {
            try {
                $config = [
                    'api_key' => $_ENV['OPENAI_API_KEY'],
                    'model_name' => $_ENV['OPENAI_MODEL_NAME'],
                    'api_url' => "https://api.openai.com/v1/chat/completions",
                    'temperature' => $_ENV['TEMPERATURE'] ?? 0.5,
                    'max_tokens' => (int)($_ENV['MAX_TOKENS'] ?? 2000)
                ];
                $responses['gpt'] = $this->callOpenAI($prompt, $config);
                
                if (isset($responses['gpt'])) {
                    FileHelper::writeFile(getcwd() .'/results/openai_response'. uniqid() .'.txt', $responses['gpt'], $this->logger);
                }

            } catch (GuzzleException $e) {
                $this->logger->warning("OpenAI API call failed", ['error' => $e->getMessage()]);
            }
        }

        // Anthropic Claude
        if (!empty($_ENV['ANTHROPIC_API_KEY'])) {
            try {
                $config = [
                    'api_key' => $_ENV['ANTHROPIC_API_KEY'],
                    'model_name' => $_ENV['ANTHROPIC_MODEL_NAME'],
                    'api_url' => "https://api.anthropic.com/v1/messages",
                    'temperature' => $_ENV['TEMPERATURE'] ?? 0.5,
                    'max_tokens' => (int)($_ENV['MAX_TOKENS'] ?? 2000)
                ];
                $responses['claude'] = $this->callAnthropic($prompt, $config);

                if (isset($responses['claude'])) {
                    FileHelper::writeFile(getcwd() .'/results/anthropic_response'. uniqid() .'.txt', $responses['claude'], $this->logger);
                }

            } catch (GuzzleException $e) {
                $this->logger->warning("Anthropic API call failed", ['error' => $e->getMessage()]);
            }
        }

        // Google Gemini
        if (!empty($_ENV['GEMINI_API_KEY'])) {
            try {
                $config = [
                    'api_key' => $_ENV['GEMINI_API_KEY'],
                    'model_name' => $_ENV['GEMINI_MODEL_NAME'],
                    'api_url' => "https://generativelanguage.googleapis.com/v1beta/models/",
                    'temperature' => $_ENV['TEMPERATURE'] ?? 0.5,
                    'max_tokens' => (int)($_ENV['MAX_TOKENS'] ?? 2000)
                ];
                $responses['gemini'] = $this->callGemini($prompt, $config);

                if (isset($responses['gemini'])) {
                    FileHelper::writeFile(getcwd() .'/results/gemini_response'. uniqid() .'.txt', $responses['gemini'], $this->logger);
                }
                
            } catch (GuzzleException $e) {
                $this->logger->warning("Gemini API call failed", ['error' => $e->getMessage()]);
            }
        }

        return $responses;
    }

    private function callOpenAI(string $prompt, array $config): ?string
    {
        $payload = [
            'model' => $config['model_name'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => $config['temperature'],
            'max_tokens' => $config['max_tokens']
        ];
        $response = $this->client->post($config['api_url'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'http_errors' => false
        ]);
        
        $statusCode = $response->getStatusCode();
        $responseBodyString = (string) $response->getBody();
        $responseBody = json_decode($responseBodyString, true);

        if ($statusCode >= 400) {
            $this->logger?->error("OpenAI API Error (Status: {statusCode}): {errorBody}", ['statusCode' => $statusCode, 'errorBody' => $responseBodyString]);
            return null;
        }
        return $responseBody['choices'][0]['message']['content'] ?? null;
    }

    private function callAnthropic(string $prompt, array $config): ?string
    {
        $payload = [
            'model' => $config['model_name'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => $config['temperature'],
            'max_tokens' => 2048,
            
        ];
        $response = $this->client->post($config['api_url'], [
            'headers' => [
                'x-api-key' => $config['api_key'],
                'anthropic-version' => $config['api_version'],
                'content-type' => 'application/json',
            ],
            'json' => $payload,
            'http_errors' => false
        ]);

        $statusCode = $response->getStatusCode();
        $responseBodyString = (string) $response->getBody();
        $responseBody = json_decode($responseBodyString, true);

        if ($statusCode >= 400) {
            $this->logger?->error("Anthropic API Error (Status: {statusCode}): {errorBody}", ['statusCode' => $statusCode, 'errorBody' => $responseBodyString]);
            return null;
        }

        if (isset($responseBody['content']) && is_array($responseBody['content']) && isset($responseBody['content'][0]['text'])) {
            return $responseBody['content'][0]['text'];
        }
        $this->logger?->warning("Anthropic API response format unexpected: {responseBody}", ['responseBody' => $responseBodyString]);
        return null;
    }

    private function callGemini(string $prompt, array $config): ?string
    {
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 2048]
        ];
        $response = $this->client->post($config['api_url'], [
            'query' => ['key' => $config['api_key']],
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/json'],
            'http_errors' => false 
        ]);

        $statusCode = $response->getStatusCode();
        $responseBodyString = (string) $response->getBody();
        $responseBody = json_decode($responseBodyString, true);

        if ($statusCode >= 400) {
            $this->logger?->error("Gemini API Error (Status: {statusCode}): {errorBody}", ['statusCode' => $statusCode, 'errorBody' => $responseBodyString]);
            return null;
        }
        return $responseBody['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }
} 