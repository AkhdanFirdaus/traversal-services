<?php

namespace Pipeline;

class Reporter
{
    public function __construct() {}

    public function run(string $initialReport, string $finalReport, string $downloadDir): array {
        try {

            $initialReport = json_decode($initialReport, true);
            $finalReport = json_decode($finalReport, true);
    
            $initialMsiScore = $initialReport['stats']['msi'] ?? '-';
            // Extract MSI and mutation stats
            $msiScore = $finalReport['stats']['msi'] ?? '-';
            $coverage = $finalReport['stats']['mutationCodeCoverage'] ?? '-';
            $killed = $finalReport['stats']['killedCount'] ?? 0;
            $escaped = $finalReport['stats']['escapedCount'] ?? 0;
            $total = $finalReport['stats']['totalMutantsCount'] ?? 0;
    
            // Initialize test cases from escaped mutants
            $testcases = [];
    
            foreach ($finalReport['escaped'] as $escapedMutant) {
                $mutator = $escapedMutant['mutator'];
                $filePath = ltrim($mutator['originalFilePath'], '/app/');
                $line = $mutator['originalStartLine'];
                $badCode = trim($mutator['originalSourceCode']);
                $fixedCode = trim($mutator['mutatedSourceCode']);
    
                $testcases[] = [
                    'file' => $filePath,
                    'line' => $line,
                    'description' => $mutator['mutatorName'] . ' mutation escaped',
                    'badCode' => $badCode,
                    'fixedCode' => $fixedCode,
                ];
            }
    
            // Final report structure
            $result = [
                'msi_score' => number_format($initialMsiScore, 2) . '%',
                'msi_score_after' => number_format($msiScore, 2) . '%',
                'detail' => [
                    'coverage' => number_format($coverage, 2) . '%',
                    'kill' => $killed,
                    'unkill' => $escaped,
                    'total' => $total
                ],
                'testcases' => $testcases,
                'download_path' => $downloadDir,
            ];
    
            return $result;
        } catch (\Throwable $th) {
            return [];
        }
    }

    public function save(string $outputDir, array $report, string $filename) {
        file_put_contents($outputDir . DIRECTORY_SEPARATOR . $filename, $report);
    }
} 