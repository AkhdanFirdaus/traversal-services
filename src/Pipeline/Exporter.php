<?php

namespace Pipeline;

use Utils\Logger;
use Utils\SocketNotifier;
use ZipArchive;

class Exporter
{
    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier,
        private string $sourceDir,
        private string $targetDir,
    ) {}

    public function run($iterate): array
    {
        // Grab all the files contained in $testDir
        $testFiles = glob($this->sourceDir . '/*.php');

        if (empty($testFiles)) {
            throw new \RuntimeException("No test files found in $this->sourceDir");
        }
        
        // Create downloadable ZIP archive from the export directory
        // $repoName = basename($this->sourceDir);
        // $timestamp = date('Y-m-d_H-i-s');
        // $zipName = sprintf('generated_test_cases_%s_%s.zip', $repoName, $timestamp);
        $zipName = sprintf('generated_test_cases_%s.zip', $iterate);
        $zipPath = $this->createZipArchive($zipName, $this->targetDir, $testFiles);

        return [
            'exportDir' => $this->targetDir,
            'zipPath' => $zipPath
        ];
    }

    private function createZipArchive(string $zipName, string $exportDir, array $selectedTests): string
    {
        $zipPath = $exportDir . DIRECTORY_SEPARATOR . $zipName;
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
} 