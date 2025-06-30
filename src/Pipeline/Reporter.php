<?php

namespace Pipeline;

use Utils\Logger;
use ZipArchive;

class Reporter
{
    public function __construct(
        private Logger $logger,
        private string $outputDir,
    ) {}

    public function run(string $initialReport, string $finalReport): array
    {
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


            if (empty($testcases)) {
                throw new \RuntimeException("No test files found");
            }

            // Create downloadable ZIP archive from the export directory
            $zipName = 'generated_test_cases.zip';
            $zipPath = $this->createZipArchive($zipName, $testcases);

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
                'download_path' => $zipPath,
            ];

            return $result;
        } catch (\Throwable $th) {
            return [];
        }
    }


    private function createZipArchive(string $zipName, array $selectedTests): string
    {
        $zipPath = $this->outputDir . DIRECTORY_SEPARATOR . $zipName;
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create ZIP archive at $zipPath");
        }

        try {
            foreach ($selectedTests as $file) {
                $zip->addFile($file, $file);
            }

            $zip->close();

            $this->logger->info("Exporter: Created ZIP archive", [
                'path' => $zipPath,
                'files' => count($selectedTests)
            ]);

            return $zipPath;
        } catch (\Exception $e) {
            $zip->close();
            throw $e;
        }
    }

    public function save(array $report, string $filename)
    {
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . $filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
