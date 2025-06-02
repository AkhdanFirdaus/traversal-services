<?php

namespace Pipeline;

use Utils\Logger;

class TestSelector
{
    public function __construct(
        private Logger $logger
    ) {}

    public function select(array $testCasesCollection): array
    {
        // $this->logger->info("Starting test case selection", [
        //     'totalCollections' => count($testCasesCollection)
        // ]);

        // $selectedTests = [];

        // foreach ($testCasesCollection as $individualTestCaseData) {
        //     if (empty($individualTestCaseData) || empty($individualTestCaseData['testCases'])) {
        //         continue;
        //     }

        //     $bestScoredTest = $this->findBestTestFromResponses($individualTestCaseData);
            

        //     if ($bestScoredTest) {
        //         $selectedTests[] = $bestScoredTest;
        //     }
        // }

        // $this->logger->info("Test case selection completed", [
        //     'selectedTestsCount' => count($selectedTests)
        // ]);

        // return $selectedTests;

        // For manual cross-check mode, we will return all test cases with their AI responses
        $this->logger->info("Starting test case selection (manual cross-check mode)", [
            'totalCollections' => count($testCasesCollection)
        ]);

        $allTests = [];

        foreach ($testCasesCollection as $individualTestCaseData) {
            if (empty($individualTestCaseData) || empty($individualTestCaseData['testCases'])) {
                continue;
            }

            $aiResponses = $individualTestCaseData['testCases'];
            foreach ($aiResponses as $modelName => $responseCode) {
                if (empty($responseCode)) {
                    continue;
                }
                $allTests[] = [
                    'type' => $individualTestCaseData['type'],
                    'source' => $individualTestCaseData['type'] === 'vulnerability'
                        ? ($individualTestCaseData['vulnerability'] ?? null)
                        : ($individualTestCaseData['mutant'] ?? null),
                    'selectedModel' => $modelName,
                    'testCode' => $responseCode,
                    'score' => $this->scoreTestResponse($responseCode, $individualTestCaseData['type'], $individualTestCaseData)
                ];
            }
        }

        $this->logger->info("Test case selection completed (manual cross-check mode)", [
            'selectedTestsCount' => count($allTests)
        ]);

        return $allTests;
    }

    private function findBestTestFromResponses(array $testCaseData): ?array
    {
        $aiResponses = $testCaseData['testCases']; // e.g., ['gemini' => '<code>', 'openai' => '<code>']
        $bestResponse = null;
        $highestScore = -1;
        $bestModelName = null;

        foreach ($aiResponses as $modelName => $responseCode) {
            if (empty($responseCode)) {
                continue;
            }
            $currentScore = $this->scoreTestResponse($responseCode, $testCaseData['type'], $testCaseData);
            if ($currentScore > $highestScore) {
                $highestScore = $currentScore;
                $bestResponse = $responseCode;
                $bestModelName = $modelName;
            }
        }

        if (!$bestResponse) {
            return null;
        }

        return [
            'type' => $testCaseData['type'],
            'source' => $testCaseData['type'] === 'vulnerability'
                ? ($testCaseData['vulnerability'] ?? null) // Ensure keys exist
                : ($testCaseData['mutant'] ?? null),
            'selectedModel' => $bestModelName,
            'testCode' => $bestResponse,
            'score' => $highestScore // Optionally include the score
        ];
    }

    private function scoreTestResponse(string $responseCode, string $testType, array $originalTestCaseData): int
    {
        $score = 0;
        $responseLower = strtolower($responseCode); // For case-insensitive checks

        // Presence of basic PHP test structure
        if (str_contains($responseLower, '<?php') || str_contains($responseLower, 'class ') || str_contains($responseLower, 'function test')) {
            $score += 10;
        }
        if (str_contains($responseLower, 'extends testcase')) { // More specific PHPUnit check
            $score += 5;
        }

        // Presence of assertions
        if (str_contains($responseLower, 'assert')) {
            $score += 5;
        }

        // Number of test methods (simple regex)
        $score += preg_match_all('/public function test\w+/i', $responseCode) * 2;

        // Setup/Teardown methods
        if (str_contains($responseLower, 'function setup(): void') || str_contains($responseLower, 'protected function setup(): void')) {
            $score += 3;
        }
        if (str_contains($responseLower, 'function teardown(): void') || str_contains($responseLower, 'protected function teardown(): void')) {
            $score += 3;
        }

        // Docblocks (basic check)
        if (str_contains($responseCode, '/**')) {
            $score += 2;
        }

        // Data providers
        if (str_contains($responseLower, '@dataprovider')) {
            $score += 5;
        }

        // Type-specific scoring
        if ($testType === 'vulnerability') {
            if (str_contains($responseLower, 'assertfalse') || str_contains($responseLower, 'assertthrows') || str_contains($responseLower, 'expectexception')) {
                $score += 5; // Security relevant assertions
            }
            // Check for path traversal characters if it's that kind of vulnerability
            // This might need to be more context-aware based on $originalTestCaseData['vulnerability']['type'] or similar
            if (str_contains($responseCode, '../') || str_contains($responseCode, '..\\')) {
                $score += 5;
            }
        } elseif ($testType === 'mutant' && isset($originalTestCaseData['mutant'])) {
            $mutantInfo = $originalTestCaseData['mutant'];
            // Check if the test seems to reference original or mutated logic (very basic)
            if (!empty($mutantInfo['originalCode']) && str_contains($responseCode, $mutantInfo['originalCode'])) {
                $score += 5;
            }
            if (!empty($mutantInfo['replacement']) && str_contains($responseCode, $mutantInfo['replacement'])) {
                $score += 5;
            }
        }

        return $score;
    }
}