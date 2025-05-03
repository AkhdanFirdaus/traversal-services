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

// 3. Detect Traversal Risks
$vulns = Detector::detectTraversalRisks($results);

// 4. Mutate
Mutator::mutateVulnerableFiles($vulns);

// 5. Generate Test Cases
TestGenerator::generateTestCases($vulns);

// 6. Run Infection
$mutationScore = InfectionRunner::run();

// 7. Generate Report
Reporter::generateReport($mutationScore, $vulns);

echo "Flow Completed. Check /workspace/reports/\n";

// rm -rf workspace/* build/ infection-log.txt