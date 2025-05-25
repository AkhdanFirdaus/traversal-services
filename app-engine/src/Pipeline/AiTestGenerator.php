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
    private string $llmApiUrl; // e.g., Google's Gemini API endpoint
    private string $llmApiKey; // API Key for the LLM service

    // Constants for Gemini API (example)
    private const GEMINI_FLASH_MODEL = "gemini-2.0-flash"; // Check for latest model
    private const GEMINI_API_BASE_URL = "https://generativelanguage.googleapis.com/v1beta/models/";


    public function __construct(?Logger $logger = null, ?HttpClient $httpClient = null, string $llmApiKey = '', string $modelName = self::GEMINI_FLASH_MODEL)
    {
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? new HttpClient(['timeout' => 60.0]); // 60 second timeout
        $this->llmApiKey = $llmApiKey; // Should be securely managed, e.g., via env vars
        $this->llmApiUrl = self::GEMINI_API_BASE_URL . $modelName . ":generateContent";

        if (empty($this->llmApiKey)) {
            $this->logger?->warning("LLM API Key is not configured for AiTestGenerator. Test generation will likely fail.");
            // In a real app, this might throw an exception or disable the feature.
        }
    }

    /**
     * Generates test cases for a given vulnerability.
     *
     * @param VulnerabilityLocation $vulnerability The vulnerability details.
     * @param array $existingTestsContext (Optional) Snippets or names of existing tests for context.
     * @param string $testFramework (Optional) Target test framework (e.g., "PHPUnit", "Pest").
     * @return string|null The generated test case code as a string, or null on failure.
     */
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

        try {
            // Structure for Gemini API
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]]
                    ]
                ],
                // Add generationConfig if needed (temperature, topK, etc.)
                // 'generationConfig' => [
                //     'temperature' => 0.7,
                //     'topK' => 40,
                // ]
            ];

            $response = $this->httpClient->post($this->llmApiUrl, [
                'query' => ['key' => $this->llmApiKey],
                'json' => $payload,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            $responseBody = json_decode((string) $response->getBody(), true);

            if (isset($responseBody['candidates'][0]['content']['parts'][0]['text'])) {
                $generatedCode = $responseBody['candidates'][0]['content']['parts'][0]['text'];
                $this->logger?->info("Successfully received test case code from AI.");
                $this->logger?->debug("Raw AI Response Text:\n{aiResponse}", ['aiResponse' => $generatedCode]);
                return $this->extractPhpCodeBlock($generatedCode); // Extract only the PHP code part
            } else {
                $this->logger?->error("AI response does not contain expected text content. Response: {response}", [
                    'response' => json_encode($responseBody)
                ]);
                return null;
            }
        } catch (RequestException $e) {
            $this->logger?->error("HTTP request to LLM API failed: {errorMessage}. Response: {responseBody}", [
                'errorMessage' => $e->getMessage(),
                'responseBody' => $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response body'
            ]);
            return null;
        } catch (Throwable $e) {
            $this->logger?->error("An unexpected error occurred during AI test generation: {errorMessage}", [
                'errorMessage' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function buildPromptForVulnerability(
        VulnerabilityLocation $vuln,
        array $existingTestsContext,
        string $testFramework
    ): string {
        // This is a critical part and requires careful "prompt engineering".
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
        $prompt .= "Consider edge cases and different ways the path could be manipulated (e.g., basic traversal, encoded traversal if applicable based on the input pattern).\n";
        $prompt .= "The test should include assertions to verify if the exploit was successful (e.g., checking if unexpected file content was read, or if an expected error/exception was NOT thrown when it should have been, or if a specific error IS thrown indicating access denial).\n";
        $prompt .= "If the vulnerable code involves file inclusion, the test might try to include an unexpected file and check for its output or side effects.\n";
        $prompt .= "If it involves reading a file, try to read a system file or a file from a parent directory.\n";
        $prompt .= "If it involves writing/moving a file, try to write to an unauthorized location.\n\n";
        $prompt .= "Please provide ONLY the PHP code for the test case, enclosed in a single PHP code block (```php ... ```).\n";
        $prompt .= "Ensure the test case is complete, runnable, and follows {$testFramework} conventions (e.g., class structure, method naming, assertions).\n";
        $prompt .= "Assume necessary setup (like a test environment or mock file system if absolutely needed) can be handled, but focus on the core test logic for the exploit attempt and assertion.\n";
        $prompt .= "If the code uses a specific framework (e.g., Laravel, Symfony), try to make the test compatible if possible, but a plain {$testFramework} test is also acceptable.\n";
        $prompt .= "Do not include any explanatory text outside the PHP code block.\n";

        return $prompt;
    }

    /**
     * Extracts PHP code from a markdown-style code block.
     */
    private function extractPhpCodeBlock(string $rawText): ?string
    {
        // Try to find ```php ... ``` or ``` ... ```
        if (preg_match('/```(?:php)?\s*([\s\S]+?)\s*```/', $rawText, $matches)) {
            return trim($matches[1]);
        }
        // If no markdown block, assume the whole text might be code (less reliable)
        // but check if it starts with <?php
        if (str_starts_with(trim($rawText), '<?php')) {
            return trim($rawText);
        }
        $this->logger?->warning("Could not extract PHP code block from AI response. Response might not be code or is malformed.");
        return $rawText; // Return raw if no block found, might need manual inspection
    }

    // TODO: Add methods for generating tests for surviving mutants from Infection.
    // This would involve a different prompt structure, including the original code,
    // the mutated code, and the test that failed to kill the mutant.
}
