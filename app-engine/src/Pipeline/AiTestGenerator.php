<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\AST\VulnerabilityLocation;
use App\Utils\Logger;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class AiTestGenerator
{
    private ?Logger $logger;
    private HttpClient $httpClient;

    // Konfigurasi LLM
    private array $llmConfigs;
    private array $llmPreferenceOrder;

    // Konstanta untuk URL API dan default model (bisa juga dari config)
    private const GEMINI_API_BASE_URL = "https://generativelanguage.googleapis.com/v1beta/models/";
    private const OPENAI_API_BASE_URL = "https://api.openai.com/v1/chat/completions";
    private const ANTHROPIC_API_BASE_URL = "https://api.anthropic.com/v1/messages";

    public function __construct(
        ?Logger $logger = null,
        ?HttpClient $httpClient = null,
        array $llmConfigs = [], // Berisi konfigurasi untuk setiap LLM
        string $preferenceOrderString = 'gemini,openai,anthropic' // Default order
    ) {
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? new HttpClient(['timeout' => (float)($_ENV['HTTP_CLIENT_TIMEOUT'] ?? 60.0)]);
        $this->llmConfigs = $this->validateAndPrepareLlmConfigs($llmConfigs);
        $this->llmPreferenceOrder = array_map('trim', explode(',', $preferenceOrderString));

        if (empty($this->llmConfigs)) {
            $this->logger?->warning("AiTestGenerator: No valid LLM configurations provided. Test generation will be skipped.");
        }
    }

    private function validateAndPrepareLlmConfigs(array $configs): array
    {
        $validConfigs = [];
        // Gemini
        if (!empty($configs['gemini']['api_key']) && !empty($configs['gemini']['model_name'])) {
            $validConfigs['gemini'] = [
                'api_key' => $configs['gemini']['api_key'],
                'model_name' => $configs['gemini']['model_name'],
                'api_url' => self::GEMINI_API_BASE_URL . $configs['gemini']['model_name'] . ":generateContent",
            ];
        }
        // OpenAI
        if (!empty($configs['openai']['api_key']) && !empty($configs['openai']['model_name'])) {
            $validConfigs['openai'] = [
                'api_key' => $configs['openai']['api_key'],
                'model_name' => $configs['openai']['model_name'],
                'api_url' => self::OPENAI_API_BASE_URL, // URL base, model dikirim dalam payload
            ];
        }
        // Anthropic
        if (!empty($configs['anthropic']['api_key']) && !empty($configs['anthropic']['model_name'])) {
            $validConfigs['anthropic'] = [
                'api_key' => $configs['anthropic']['api_key'],
                'model_name' => $configs['anthropic']['model_name'],
                'api_url' => self::ANTHROPIC_API_BASE_URL,
                'api_version' => $configs['anthropic']['api_version'] ?? '2023-06-01',
            ];
        }
        return $validConfigs;
    }

    public function generateTestsForVulnerability(
        VulnerabilityLocation $vulnerability,
        array $existingTestsContext = [],
        string $testFramework = "PHPUnit"
    ): ?string {
        $this->logger?->info("Attempting to generate AI test case for vulnerability in {filePath} at line {lineNumber}", [
            'filePath' => $vulnerability->filePath,
            'lineNumber' => $vulnerability->startLine
        ]);

        $prompt = $this->buildPromptForVulnerability($vulnerability, $existingTestsContext, $testFramework);
        if (!$prompt) {
            $this->logger?->error("Failed to build prompt for AI test generation.");
            return null;
        }
        $this->logger?->debug("Generated LLM Prompt:\n{prompt}", ['prompt' => $prompt]);

        foreach ($this->llmPreferenceOrder as $llmType) {
            if (isset($this->llmConfigs[$llmType])) {
                $this->logger?->info("Trying LLM provider: {llmType}", ['llmType' => $llmType]);
                $config = $this->llmConfigs[$llmType];
                $generatedCode = null;

                try {
                    switch ($llmType) {
                        case 'gemini':
                            $generatedCode = $this->callGeminiApi($prompt, $config);
                            break;
                        case 'openai':
                            $generatedCode = $this->callOpenAiApi($prompt, $config);
                            break;
                        case 'anthropic':
                            $generatedCode = $this->callAnthropicApi($prompt, $config);
                            break;
                    }

                    if ($generatedCode) {
                        $this->logger?->info("Successfully received test case code from {llmType}.", ['llmType' => $llmType]);
                        return $this->extractPhpCodeBlock($generatedCode);
                    } else {
                        $this->logger?->warning("LLM provider {llmType} returned no code or an empty response.", ['llmType' => $llmType]);
                    }
                } catch (Throwable $e) {
                    $this->logger?->error("Error with LLM provider {llmType}: {errorMessage}", [
                        'llmType' => $llmType,
                        'errorMessage' => $e->getMessage()
                    ]);
                    // Jangan hentikan, coba LLM berikutnya jika ada
                }
            }
        }

        $this->logger?->error("All configured LLM providers failed to generate a test case.");
        return null;
    }

    private function callGeminiApi(string $prompt, array $config): ?string
    {
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            // 'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 2048] // Sesuaikan
        ];
        $response = $this->httpClient->post($config['api_url'], [
            'query' => ['key' => $config['api_key']],
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/json']
        ]);
        $responseBody = json_decode((string) $response->getBody(), true);
        return $responseBody['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    private function callOpenAiApi(string $prompt, array $config): ?string
    {
        $payload = [
            'model' => $config['model_name'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            // 'temperature' => 0.7, 'max_tokens' => 2048 // Sesuaikan
        ];
        $response = $this->httpClient->post($config['api_url'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'json' => $payload
        ]);
        $responseBody = json_decode((string) $response->getBody(), true);
        return $responseBody['choices'][0]['message']['content'] ?? null;
    }

    private function callAnthropicApi(string $prompt, array $config): ?string
    {
        $payload = [
            'model' => $config['model_name'],
            'max_tokens' => 2048, // Sesuaikan
            'messages' => [['role' => 'user', 'content' => $prompt]],
            // 'temperature' => 0.7 // Sesuaikan
        ];
        $response = $this->httpClient->post($config['api_url'], [
            'headers' => [
                'x-api-key' => $config['api_key'],
                'anthropic-version' => $config['api_version'],
                'content-type' => 'application/json',
            ],
            'json' => $payload
        ]);
        $responseBody = json_decode((string) $response->getBody(), true);
        // Respons Anthropic Claude v1/messages biasanya ada di 'content' -> array -> 'text'
        if (isset($responseBody['content']) && is_array($responseBody['content']) && isset($responseBody['content'][0]['text'])) {
            return $responseBody['content'][0]['text'];
        }
        return null;
    }

    private function buildPromptForVulnerability(
        VulnerabilityLocation $vuln,
        array $existingTestsContext,
        string $testFramework
    ): string {
        // Prompt ini bisa disesuaikan lebih lanjut per LLM jika diperlukan,
        // tapi untuk saat ini kita gunakan prompt generik yang sama.
        $prompt = "You are an expert PHP security testing assistant.\n";
        $prompt .= "A potential vulnerability has been identified in a PHP project.\n\n";
        $prompt .= "Vulnerability Details:\n";
        $prompt .= "- Type: Path Traversal ({$vuln->cweId} - {$vuln->ruleName})\n";
        $prompt .= "- File: {$vuln->filePath}\n";
        $prompt .= "- Lines: {$vuln->startLine}-{$vuln->endLine}\n";
        $prompt .= "- Sink Function: {$vuln->sinkFunction}\n";
        $prompt .= "- Potentially Vulnerable Input/Pattern: {$vuln->vulnerableInputDescription}\n";
        $prompt .= "- Rule Description: {$vuln->ruleDescription}\n\n";
        $prompt .= "Code Snippet (lines {$vuln->startLine}-{$vuln->endLine}):\n```php\n{$vuln->codeSnippet}\n```\n\n";

        if (!empty($existingTestsContext)) {
            $prompt .= "For context, here are some existing test names or snippets related to this file/area (if any):\n";
            foreach ($existingTestsContext as $testCtx) {
                $prompt .= "- {$testCtx}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "Your task is to generate a new, effective {$testFramework} test case that specifically targets this potential path traversal vulnerability.\n";
        $prompt .= "The test should attempt to exploit the vulnerability by providing malicious input that tries to access files or directories outside the intended scope (e.g., trying to read '/etc/passwd' or a sensitive file via '../' sequences).\n";
        $prompt .= "Consider edge cases and different ways the path could be manipulated.\n";
        $prompt .= "The test should include assertions to verify if the exploit was successful or if access was correctly denied.\n";
        $prompt .= "If the vulnerable code involves file inclusion, the test might try to include an unexpected file and check for its output or side effects.\n";
        $prompt .= "If it involves reading a file, try to read a system file or a file from a parent directory.\n";
        $prompt .= "If it involves writing/moving a file, try to write to an unauthorized location.\n\n";
        $prompt .= "Please provide ONLY the PHP code for the test case, enclosed in a single PHP code block (```php ... ```).\n";
        $prompt .= "Ensure the test case is complete, runnable, and follows {$testFramework} conventions (e.g., class structure, method naming, assertions).\n";
        $prompt .= "Do not include any explanatory text outside the PHP code block.\n";

        return $prompt;
    }

    private function extractPhpCodeBlock(string $rawText): ?string
    {
        if (preg_match('/```(?:php)?\s*([\s\S]+?)\s*```/', $rawText, $matches)) {
            return trim($matches[1]);
        }
        if (str_starts_with(trim($rawText), '<?php')) { // Fallback jika tidak ada markdown block
            return trim($rawText);
        }
        $this->logger?->warning("Could not extract PHP code block from AI response. Response might not be code or is malformed.", ['rawResponse' => substr($rawText, 0, 200)]);
        return $rawText; // Kembalikan mentah jika tidak ada blok, mungkin perlu inspeksi manual
    }
}
