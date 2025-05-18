<?php

namespace App;

use App\helpers\Utils;
use App\pipeline\Analyzer;
use App\pipeline\Detector;
use App\Pipeline\InfectionRunner;
use App\pipeline\PayloadGenerator;

class Main {
    public static function pipeline(string $repoUrl): array {
        // 0.1. Git Clone
        Utils::cloneRepo($repoUrl);

        // 0.2 Payloads generation
        $pg = new PayloadGenerator('/app/src/patterns/patterns.json');
        $payloads = $pg->getPayloads();
        Utils::saveReport('1-payload-generation', $payloads);

        // 1. Scanning
        $results = Analyzer::analyzeTestCases('/app/workspace/repo');
        $reportBefore = Utils::saveReport('1-analyze', $results);

        // // 2. Running Infection (Before)
        // $mutationScore = InfectionRunner::run();
        // $reportBefore = Utils::saveReport('2-infection', $mutationScore);

        // // 3. Detect Traversal Risks
        // $patterns = $pg->getOriginalPatterns();
        // $detector = new Detector($patterns);
        // $vulns = $detector->detect($results);
        // $reportAfter = Utils::saveReport('3-detector', $vulns);

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
        // Reporter::generateReport($mutationScore, $vulns); -> bisa di download pake wget temporary 1jam
        // Report 1:
        // Report 2:
        // // Utils::saveReport('7-report', $results);
        
        return [
            'report-before' => $reportBefore,
            // 'report-after' => $reportAfter,
            // 'zip-path' => $reportAfter,
        ];
    }
    
    public static function run(string $repoUrl): void {
        if (!$repoUrl) {
            die("Usage: php run.php <repository-url>\n");
        }

        self::pipeline($repoUrl);

        echo "Flow Completed. Check /workspace/reports/\n";
    }

    public static function runApi(string $repoUrl): array {
        $result = self::pipeline($repoUrl);
        return $result;
    }
}