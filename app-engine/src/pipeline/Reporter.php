<?php

namespace App\Pipeline;

class Reporter {
    public static function generateReport(float $score, array $vulns): void {
        $report = [
            'mutation_score' => $score,
            'vulnerabilities_detected' => count($vulns),
            'timestamp' => date('c')
        ];
        if (!is_dir('./workspace/reports')) mkdir('./workspace/reports', 0777, true);
        file_put_contents('./workspace/reports/report.json', json_encode($report, JSON_PRETTY_PRINT));
    }
}

