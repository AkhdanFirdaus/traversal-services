<?php

namespace Pipeline;

use Utils\Logger;

class TestSelector
{
    public function __construct(
        private Logger $logger
    ) {}

    public function select(array $testCases): array
    {
        $this->logger->info("Starting test case selection", [
            'totalCases' => count($testCases)
        ]);

        $selectedTests = [];

        foreach ($testCases as $testCase) {
            if (!$testCase) {
                continue;
            }

            $selected = $this->selectBestResponse($testCase);
            if ($selected) {
                $selectedTests[] = $selected;
            }
        }

        $this->logger->info("Test case selection completed", [
            'selectedTests' => count($selectedTests)
        ]);

        return $selectedTests;
    }

    private function selectBestResponse(array $testCase): ?array
    {
        $responses = $testCase['testCases'] ?? [];
        if (empty($responses)) {
            return null;
        }

        // Score each response
        $scores = [];
        foreach ($responses as $model => $response) {
            $scores[$model] = $this->scoreResponse($response, $testCase);
        }

        // Get the model with the highest score
        arsort($scores);
        $bestModel = key($scores);

        if (!$bestModel) {
            return null;
        }

        return [
            'type' => $testCase['type'],
            'source' => $testCase['type'] === 'vulnerability' 
                ? $testCase['vulnerability'] 
                : $testCase['mutant'],
            'selectedModel' => $bestModel,
            'testCode' => $responses[$bestModel]
        ];
    }

    private function scoreResponse(string $response, array $testCase): int
    {
        $score = 0;

        // Check if response contains PHP code
        if (strpos($response, '<?php') !== false || 
            strpos($response, 'class') !== false || 
            strpos($response, 'function test') !== false) {
            $score += 10;
        }

        // Check for PHPUnit specific content
        if (strpos($response, 'extends TestCase') !== false) {
            $score += 5;
        }

        if (strpos($response, 'assert') !== false) {
            $score += 5;
        }

        // Check for test method declarations
        if (preg_match_all('/public function test\w+/i', $response, $matches)) {
            $score += count($matches[0]) * 2;
        }

        // Check for proper setup/teardown
        if (strpos($response, 'setUp') !== false) {
            $score += 3;
        }
        if (strpos($response, 'tearDown') !== false) {
            $score += 3;
        }

        // Check for docblocks
        if (strpos($response, '/**') !== false) {
            $score += 2;
        }

        // Check for test data providers
        if (strpos($response, '@dataProvider') !== false) {
            $score += 5;
        }

        // Specific checks based on test type
        if ($testCase['type'] === 'vulnerability') {
            // Check for security-related assertions
            if (strpos($response, 'assertFalse') !== false || 
                strpos($response, 'assertThrows') !== false) {
                $score += 5;
            }

            // Check for path traversal specific tests
            if (strpos($response, '../') !== false || 
                strpos($response, '..\\') !== false) {
                $score += 5;
            }
        } else { // Mutation test
            // Check if both original and mutated cases are tested
            if (strpos($response, $testCase['mutant']['originalCode']) !== false &&
                strpos($response, $testCase['mutant']['replacement']) !== false) {
                $score += 10;
            }
        }

        return $score;
    }
} 