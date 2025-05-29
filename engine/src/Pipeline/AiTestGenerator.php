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
    // Urutan preferensi bisa digunakan jika kita ingin mencoba satu per satu dan berhenti,
    // atau untuk menentukan LLM "utama" jika hanya satu yang dibutuhkan.
    // Untuk permintaan ini, kita akan mencoba semua yang terkonfigurasi.
    private array $llmProvidersToTry;

    // Konstanta untuk URL API dan default model
    private const GEMINI_API_BASE_URL = "https://generativelanguage.googleapis.com/v1beta/models/";
    private const OPENAI_API_BASE_URL = "https://api.openai.com/v1/chat/completions";
    private const ANTHROPIC_API_BASE_URL = "https://api.anthropic.com/v1/messages";

    public function __construct(
        ?Logger $logger = null,
        ?HttpClient $httpClient = null,
        array $llmConfigsFromApp = [], // Berisi konfigurasi untuk setiap LLM
        string $preferenceOrderString = 'gemini,openai,anthropic' // Bisa digunakan untuk menentukan provider mana saja yang akan dicoba
    ) {
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? new HttpClient(['timeout' => (float)($_ENV['HTTP_CLIENT_TIMEOUT'] ?? 60.0)]);
        $this->llmConfigs = $this->validateAndPrepareLlmConfigs($llmConfigsFromApp);
        
        // Tentukan LLM mana yang akan dicoba berdasarkan yang terkonfigurasi dan mungkin preferensi
        $this->llmProvidersToTry = [];
        $preferredOrder = array_map('trim', explode(',', $preferenceOrderString));
        foreach ($preferredOrder as $provider) {
            if (isset($this->llmConfigs[$provider])) {
                $this->llmProvidersToTry[] = $provider;
            }
        }
        // Jika ada provider terkonfigurasi yang tidak ada di preferenceOrder, tambahkan di akhir
        foreach (array_keys($this->llmConfigs) as $provider) {
            if (!in_array($provider, $this->llmProvidersToTry)) {
                $this->llmProvidersToTry[] = $provider;
            }
        }


        if (empty($this->llmConfigs) || empty($this->llmProvidersToTry)) {
            $this->logger?->warning("AiTestGenerator: No valid LLM configurations provided or to try. Test generation will be skipped.");
        }
    }

    private function validateAndPrepareLlmConfigs(array $configsInput): array
    {
        $validConfigs = [];
        // Gemini
        if (!empty($configsInput['gemini']['api_key']) && !empty($configsInput['gemini']['model_name'])) {
            $validConfigs['gemini'] = [
                'api_key' => $configsInput['gemini']['api_key'],
                'model_name' => $configsInput['gemini']['model_name'],
                'api_url' => self::GEMINI_API_BASE_URL . $configsInput['gemini']['model_name'] . ":generateContent",
            ];
        }
        // OpenAI
        if (!empty($configsInput['openai']['api_key']) && !empty($configsInput['openai']['model_name'])) {
            $validConfigs['openai'] = [
                'api_key' => $configsInput['openai']['api_key'],
                'model_name' => $configsInput['openai']['model_name'],
                'api_url' => self::OPENAI_API_BASE_URL,
            ];
        }
        // Anthropic
        if (!empty($configsInput['anthropic']['api_key']) && !empty($configsInput['anthropic']['model_name'])) {
            $validConfigs['anthropic'] = [
                'api_key' => $configsInput['anthropic']['api_key'],
                'model_name' => $configsInput['anthropic']['model_name'],
                'api_url' => self::ANTHROPIC_API_BASE_URL,
                'api_version' => $configsInput['anthropic']['api_version'] ?? '2023-06-01',
            ];
        }
        return $validConfigs;
    }

    /**
     * Generates test cases for a given vulnerability from all configured LLM providers.
     *
     * @param VulnerabilityLocation $vulnerability The vulnerability details.
     * @param array $existingTestsContext (Optional) Snippets or names of existing tests for context.
     * @param string $testFramework (Optional) Target test framework (e.g., "PHPUnit", "Pest").
     * @return array An arraykeyed by LLM provider name (e.g., 'gemini', 'openai'),
     * each containing the generated test case code as a string, or null if failed for that provider.
     * Example: ['gemini' => '<?php ... ?>', 'openai' => null, 'anthropic' => '<?php ... ?>']
     */
    public function generateTestsForVulnerability(
        VulnerabilityLocation $vulnerability,
        array $existingTestsContext = [],
        string $testFramework = "PHPUnit"
    ): array {
        $this->logger?->info("Attempting to generate AI test cases for vulnerability in {filePath} at line {lineNumber} using multiple LLMs.", [
            'filePath' => $vulnerability->filePath,
            'lineNumber' => $vulnerability->startLine
        ]);

        $prompt = $this->buildPromptForVulnerability($vulnerability, $existingTestsContext, $testFramework);
        if (!$prompt) {
            $this->logger?->error("Failed to build prompt for AI test generation. No tests will be generated.");
            return []; // Kembalikan array kosong jika prompt gagal dibuat
        }
        $this->logger?->debug("Generated LLM Prompt (common for all providers):\n{prompt}", ['prompt' => $prompt]);

        $allGeneratedTests = [];

        foreach ($this->llmProvidersToTry as $llmType) {
            // Pastikan lagi bahwa konfigurasi untuk llmType ini memang ada (seharusnya sudah difilter di konstruktor)
            if (!isset($this->llmConfigs[$llmType])) {
                continue;
            }

            $this->logger?->info("Requesting test case from LLM provider: {llmType}", ['llmType' => $llmType]);
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
                    default:
                        $this->logger?->warning("Unknown LLM provider type configured: {llmType}", ['llmType' => $llmType]);
                }

                if ($generatedCode) {
                    $extractedCode = $this->extractPhpCodeBlock($generatedCode);
                    if ($extractedCode) {
                        $allGeneratedTests[$llmType] = $extractedCode;
                        $this->logger?->info("Successfully received and extracted test case code from {llmType}.", ['llmType' => $llmType]);
                    } else {
                        $allGeneratedTests[$llmType] = null; // Gagal ekstraksi kode
                        $this->logger?->warning("LLM provider {llmType} returned a response, but PHP code block could not be extracted.", ['llmType' => $llmType]);
                    }
                } else {
                    $allGeneratedTests[$llmType] = null; // Tidak ada kode atau respons kosong
                    $this->logger?->warning("LLM provider {llmType} returned no code or an empty response.", ['llmType' => $llmType]);
                }
            } catch (RequestException $e) {
                $this->logger?->error("HTTP RequestException with LLM provider {llmType}: {errorMessage}. Response: {responseBody}", [
                    'llmType' => $llmType,
                    'errorMessage' => $e->getMessage(),
                    'responseBody' => $e->hasResponse() ? (string) $e->getResponse()->getBody(true) : 'No response body' // Baca stream jika ada
                ]);
                $allGeneratedTests[$llmType] = null;
            } catch (Throwable $e) {
                $this->logger?->error("Error with LLM provider {llmType}: {errorMessage}", [
                    'llmType' => $llmType,
                    'errorMessage' => $e->getMessage()
                ]);
                $allGeneratedTests[$llmType] = null;
            }
        }

        if (empty($allGeneratedTests)) {
             $this->logger?->warning("No LLM providers successfully generated a test case for this vulnerability.");
        }
        return $allGeneratedTests;
    }

    private function callGeminiApi(string $prompt, array $config): ?string
    {
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 2048]
        ];
        $response = $this->httpClient->post($config['api_url'], [
            'query' => ['key' => $config['api_key']],
            'json' => $payload,
            'headers' => ['Content-Type' => 'application/json'],
            'http_errors' => false // Agar Guzzle tidak throw exception untuk status 4xx/5xx
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

    private function callOpenAiApi(string $prompt, array $config): ?string
    {
        $payload = [
            'model' => $config['model_name'],
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.5, 'max_tokens' => 2048
        ];
        $response = $this->httpClient->post($config['api_url'], [
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

    private function callAnthropicApi(string $prompt, array $config): ?string
    {
        $payload = [
            'model' => $config['model_name'],
            'max_tokens' => 2048,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.5
        ];
        $response = $this->httpClient->post($config['api_url'], [
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

    private function buildPromptForVulnerability(
        VulnerabilityLocation $vuln,
        array $existingTestsContext,
        string $testFramework
    ): string {
        // Prompt generik, bisa disesuaikan jika ada perbedaan signifikan
        // dalam cara masing-masing LLM merespons terhadap detail tertentu.
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
        if (preg_match('/```(?:php)?\s*([\s\S]+?)\s*```/s', $rawText, $matches)) { // Tambahkan modifier 's' untuk dotall
            return trim($matches[1]);
        }
        // Fallback jika tidak ada markdown block, tapi periksa apakah ini benar-benar kode PHP
        $trimmedText = trim($rawText);
        if (str_starts_with($trimmedText, '<?php') || (str_starts_with($trimmedText, 'class ') && str_contains($trimmedText, 'extends TestCase'))) {
            return $trimmedText;
        }
        $this->logger?->warning("Could not extract PHP code block from AI response. Response might not be code or is malformed.", ['rawResponsePreview' => substr($rawText, 0, 200)]);
        // Kembalikan null jika tidak yakin itu adalah blok kode PHP yang valid
        // Atau kembalikan rawText jika ingin penanganan lebih lanjut di luar kelas ini
        return null;
    }
}
