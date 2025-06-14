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

    public function run(): array
    {
        // Grab all the files contained in $testDir
        $testFiles = glob($this->sourceDir . '/*.php');

        if (empty($testFiles)) {
            throw new \RuntimeException("No test files found in $this->sourceDir");
        }
        
        // Create downloadable ZIP archive from the export directory
        $zipPath = $this->createZipArchive($this->targetDir, $this->sourceDir, $testFiles);

        return [
            'exportDir' => $this->targetDir,
            'zipPath' => $zipPath
        ];
    }

    private function createZipArchive(string $exportDir, string $repoPath, array $selectedTests): string
    {
        $repoName = basename($repoPath);
        $timestamp = date('Y-m-d_H-i-s');
        $zipName = sprintf('generated_test_cases_%s_%s.zip', $repoName, $timestamp);
        $zipPath = $exportDir . DIRECTORY_SEPARATOR . $zipName;
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create ZIP archive at $zipPath");
        }

        try {
            foreach ($selectedTests as $file) {
                $zip->addFile($file, basename($file));
            }

            $zip->close();

            // $this->logger->info("Created ZIP archive", [
            //     'path' => $zipPath,
            //     'files' => count($selectedTests)
            // ]);

            return $zipPath;

        } catch (\Exception $e) {
            $zip->close();
            throw $e;
        }
    }
} 