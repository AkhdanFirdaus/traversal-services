<?php

namespace App;

class Main {

    public static function pipeline(string $repoUrl): array {
        $baseDir = sys_get_temp_dir() . '/workspace';

        $cloner = new RepositoryCloner($baseDir);
        $repoDir = $cloner->run($repoUrl);

        $scanner = new HeuristicScanner($repoDir, "$baseDir/reports");
        $report = $scanner->run();

        $infection = new InfectionRunner($repoDir, "$baseDir/reports");
        $infectionBefore = $infection->run("before");

        $ai = new AiAgent('openai', $report, "$baseDir/tests", "$baseDir/ai");
        $aiResults = $ai->run();

        $infectionAfter = $infection->run("after");

        $comparer = new ResultComparer("$baseDir/reports");
        $comparer->run("$baseDir/tests", "$baseDir/final");

        $packagerPath = "$baseDir/final_output.zip";
        $packager = new Packager("$baseDir/final", $packagerPath);
        $packager->run();

        echo "Pipeline complete. Output at: $baseDir/final_output.zip\n";
        return [
            'report' => $report,
            'infectionBefore' => $infectionBefore,
            'infectionAfter' => $infectionAfter,
            'aiOutput' => $aiResults,
            'finalReport' => $packagerPath,
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