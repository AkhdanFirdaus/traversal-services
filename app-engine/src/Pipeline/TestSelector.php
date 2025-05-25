<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Utils\Logger;

class TestSelector
{
    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Selects the "best" test cases based on certain criteria.
     * This is a placeholder and needs more sophisticated logic.
     *
     * @param array $generatedTests An array of generated test case data.
     * Each element could be an array/object with:
     * 'code' => string (the test code)
     * 'source_vulnerability' => VulnerabilityLocation (optional)
     * 'killed_mutants_count' => int (optional, from Infection run after adding this test)
     * 'msi_improvement' => float (optional)
     * @param float $minMsiImprovementThreshold Minimum MSI improvement to consider a test "good".
     * @param int $minKilledMutantsThreshold Minimum number of new mutants killed.
     * @return array An array of selected test case data.
     */
    public function selectBestTests(
        array $generatedTests,
        float $minMsiImprovementThreshold = 1.0,
        int $minKilledMutantsThreshold = 1
    ): array {
        $this->logger?->info("Starting test selection process for {count} generated tests.", ['count' => count($generatedTests)]);
        $selectedTests = [];

        if (empty($generatedTests)) {
            $this->logger?->info("No generated tests to select from.");
            return [];
        }

        // Example selection logic:
        // 1. Prioritize tests that show significant MSI improvement.
        // 2. Prioritize tests that kill a notable number of previously surviving mutants.
        // 3. Ensure tests are syntactically valid (this should ideally happen before selection).
        // This current implementation is very basic and assumes such data is pre-calculated.

        foreach ($generatedTests as $testData) {
            $isGoodTest = false;
            $reasons = [];

            // Assume $testData is an array with 'code' and potentially 'msi_improvement' or 'killed_mutants_count'
            if (!isset($testData['code']) || empty($testData['code'])) {
                $this->logger?->warning("Skipping test data due to missing code.", ['testData' => $testData]);
                continue;
            }

            if (isset($testData['msi_improvement']) && $testData['msi_improvement'] >= $minMsiImprovementThreshold) {
                $isGoodTest = true;
                $reasons[] = "MSI improvement of {$testData['msi_improvement']}% >= threshold {$minMsiImprovementThreshold}%";
            }

            if (isset($testData['killed_mutants_count']) && $testData['killed_mutants_count'] >= $minKilledMutantsThreshold) {
                $isGoodTest = true;
                $reasons[] = "Killed {$testData['killed_mutants_count']} mutants >= threshold {$minKilledMutantsThreshold}";
            }
            
            // If no specific metrics, select all valid tests for now (assuming they passed earlier validation)
            if (empty($reasons) && !isset($testData['msi_improvement']) && !isset($testData['killed_mutants_count'])) {
                 $isGoodTest = true; // Default to selecting if no metrics are available
                 $reasons[] = "Selected by default (no specific metrics available for filtering)";
            }


            if ($isGoodTest) {
                $this->logger?->info("Selected test. Reasons: {reasons}", ['reasons' => implode(', ', $reasons)]);
                // Add more context to selected test if needed
                $selectedTests[] = $testData; // Store the whole test data array
            } else {
                 $this->logger?->info("Test not selected (did not meet criteria). Provided metrics: MSI imp. {msi_imp}, Killed mut. {killed_mut}", [
                    'msi_imp' => $testData['msi_improvement'] ?? 'N/A',
                    'killed_mut' => $testData['killed_mutants_count'] ?? 'N/A'
                 ]);
            }
        }

        $this->logger?->info("Selected {selectedCount} out of {totalCount} generated tests.", [
            'selectedCount' => count($selectedTests),
            'totalCount' => count($generatedTests)
        ]);
        return $selectedTests;
    }

    // TODO: Implement more sophisticated selection strategies:
    // - Ranking tests based on multiple factors.
    // - Considering test execution time if available.
    // - Avoiding redundant tests if they cover the same mutants/vulnerabilities.
    // - Validating test syntax and basic runnability before selection.
}
