<?php
namespace App;

use App\Engine\Analyzer;
use App\Engine\Detector;
use App\Engine\Mutator;
use App\Engine\InfectionRunner;
use App\Engine\PayloadGenerator;
use App\Engine\Reporter;
use App\Engine\TestGenerator;
use App\Engine\Utils;

class Main {
    public static function run(array $argv): void {
        // 1. Git Clone
        $repoUrl = $argv[1] ?? null;
        if (!$repoUrl) {
            die("Usage: php run.php <repository-url>\n");
        }

        Utils::cloneRepo($repoUrl);

        // 1.1. Payloads generation
        $pg = new PayloadGenerator('/app/materials/patterns.json');
        $payloads = $pg->getPayloads();
        Utils::saveReport('1-payload-generation', $payloads);

        // 2. Static Analysis
        $results = Analyzer::analyzeSourceCode('./workspace/repo');
        Utils::saveReport('2-analyze', $results);

        // 3. Detect Traversal Risks
        $patterns = $pg->getOriginalPatterns();
        $detector = new Detector($patterns);
        $vulns = $detector->detect($results);
        Utils::saveReport('3-detector', $vulns);

        // // 4. Mutate
        // Mutator::mutateVulnerableFiles($vulns);
        // // Utils::saveReport('4-mutator', $results);

        // // 5. Generate Test Cases
        // TestGenerator::generateTestCases($vulns);
        // // Utils::saveReport('5-testgen', $results);

        // // 6. Run Infection
        // $mutationScore = InfectionRunner::run();
        // // Utils::saveReport('6-infection', $results);

        // // 7. Generate Report
        // Reporter::generateReport($mutationScore, $vulns);
        // // Utils::saveReport('7-report', $results);

        echo "Flow Completed. Check /workspace/reports/\n";
    }
}