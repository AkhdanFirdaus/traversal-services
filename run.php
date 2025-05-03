<?php
require_once __DIR__ . '/vendor/autoload.php';

use Engine\Analyzer;
use Engine\Detector;
use Engine\Mutator;
use Engine\InfectionRunner;
use Engine\Reporter;
use Engine\TestGenerator;
use Engine\Utils;

// 1. Git Clone
$repoUrl = $argv[1] ?? null;
if (!$repoUrl) {
    die("Usage: php run.php <repository-url>\n");
}

Utils::cloneRepo($repoUrl);

// 2. Static Analysis
$results = Analyzer::analyzeSourceCode('./workspace/repo');
Utils::saveReport('2-analyze', $results);

// 3. Detect Traversal Risks
$vulns = Detector::detect($results);
Utils::saveReport('3-detector', $vulns);

// 4. Mutate
Mutator::mutateVulnerableFiles($vulns);
// Utils::saveReport('4-mutator', $results);

// 5. Generate Test Cases
TestGenerator::generateTestCases($vulns);
// Utils::saveReport('5-testgen', $results);

// 6. Run Infection
$mutationScore = InfectionRunner::run();
// Utils::saveReport('6-infection', $results);

// 7. Generate Report
Reporter::generateReport($mutationScore, $vulns);
// Utils::saveReport('7-report', $results);

echo "Flow Completed. Check /workspace/reports/\n";