<?php

namespace App\Engine;

class Utils {
    public static function getCurrentTimestamp(): string {
        return date('Y-m-d H:i:s');
    }

    public static function getCurrentDate(): string {
        return date('Y-m-d');
    }

    public static function getCurrentTime(): string {
        return date('H:i:s');
    }
    
    public static function cloneRepo(string $url): void {
        $targetDir = './workspace/repo';
        if (is_dir($targetDir)) {
            exec("rm -rf $targetDir");
        }
        exec("git clone $url $targetDir");
    }

    public static function saveReport(string $stepName, $content): void {
        $reportDir = './workspace/reports/';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }
        $filePath = "$reportDir/{$stepName}.json";
        file_put_contents($filePath, json_encode($content, JSON_PRETTY_PRINT));
        echo "Report saved to $filePath\n";
    }
}
