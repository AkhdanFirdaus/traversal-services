<?php
require_once __DIR__ . '/engine/utils.php';
require_once __DIR__ . '/engine/analyzer.php';
require_once __DIR__ . '/engine/detector.php';
require_once __DIR__ . '/engine/mutator.php';
require_once __DIR__ . '/engine/test_generator.php';
require_once __DIR__ . '/engine/infection_runner.php';
require_once __DIR__ . '/engine/reporter.php';

// 1. Git Clone
$repoUrl = $argv[1] ?? null;
if (!$repoUrl) {
    die("Usage: php run.php <repository-url>\n");
}

cloneRepo($repoUrl);

// 2. Static Analysis
$results = analyzeSourceCode('./workspace/repo');

// 3. Detect Traversal Risks
$vulns = detectTraversalRisks($results);

// 4. Mutate
mutateVulnerableFiles($vulns);

// 5. Generate Test Cases
generateTestCases($vulns);

// 6. Run Infection
$mutationScore = runInfection();

// 7. Generate Report
generateReport($mutationScore, $vulns);

echo "Flow Completed. Check /workspace/reports/\n";