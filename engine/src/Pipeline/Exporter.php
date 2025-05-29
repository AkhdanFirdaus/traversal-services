<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Utils\Logger;
use App\Utils\FileHelper;
use ZipArchive;

class Exporter
{
    private ?Logger $logger;
    private string $baseExportDir;

    public function __construct(?Logger $logger = null, string $baseExportDir = 'reports/exported_test_cases')
    {
        $this->logger = $logger;
        $this->baseExportDir = rtrim($baseExportDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Exports selected test cases to a specified format (e.g., individual files, a ZIP archive).
     *
     * @param array $selectedTests Array of selected test case data (each element should have at least a 'code' key).
     * It might also have 'filePathHint' or 'className' for better organization.
     * @param string $exportName A name for this export batch (e.g., "projectX_cwe22_tests").
     * @param string $format Currently supports "zip" or "individual_files".
     * @return string|null Path to the exported file (for ZIP) or directory (for individual files), or null on failure.
     */
    public function exportTests(array $selectedTests, string $exportName, string $format = 'zip'): ?string
    {
        if (empty($selectedTests)) {
            $this->logger?->info("No selected tests to export for: {exportName}", ['exportName' => $exportName]);
            return null;
        }

        $safeExportName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $exportName);
        $exportPath = $this->baseExportDir . DIRECTORY_SEPARATOR . $safeExportName;

        if (!is_dir($this->baseExportDir)) {
            if (!mkdir($this->baseExportDir, 0775, true) && !is_dir($this->baseExportDir)) {
                $this->logger?->error("Failed to create base export directory: {baseExportDir}", ['baseExportDir' => $this->baseExportDir]);
                return null;
            }
        }

        if ($format === 'individual_files') {
            return $this->exportAsIndividualFiles($selectedTests, $exportPath);
        } elseif ($format === 'zip') {
            return $this->exportAsZip($selectedTests, $exportPath . '.zip', $safeExportName);
        } else {
            $this->logger?->error("Unsupported export format: {format}", ['format' => $format]);
            return null;
        }
    }

    private function exportAsIndividualFiles(array $selectedTests, string $directoryPath): ?string
    {
        if (is_dir($directoryPath)) {
            // Optionally, clean up or version the directory
            // FileHelper::deleteDirectoryRecursive($directoryPath, $this->logger);
        }
        if (!mkdir($directoryPath, 0775, true) && !is_dir($directoryPath)) {
            $this->logger?->error("Failed to create directory for individual test files: {directoryPath}", ['directoryPath' => $directoryPath]);
            return null;
        }

        foreach ($selectedTests as $index => $testData) {
            if (!isset($testData['code']) || empty($testData['code'])) {
                $this->logger?->warning("Skipping test export due to empty code at index {index}.", ['index' => $index]);
                continue;
            }
            // Determine filename
            $filename = $testData['filenameHint'] ?? "GeneratedTest" . ($index + 1) . ".php";
            if (!str_ends_with(strtolower($filename), '.php')) {
                $filename .= '.php';
            }

            $filePath = $directoryPath . DIRECTORY_SEPARATOR . $filename;
            if (!FileHelper::writeFile($filePath, $testData['code'], $this->logger)) {
                $this->logger?->error("Failed to write test file: {filePath}", ['filePath' => $filePath]);
                // Continue exporting other files or return null to indicate partial failure
            } else {
                $this->logger?->info("Exported test file: {filePath}", ['filePath' => $filePath]);
            }
        }
        $this->logger?->info("Successfully exported tests as individual files to: {directoryPath}", ['directoryPath' => $directoryPath]);
        return $directoryPath;
    }

    private function exportAsZip(array $selectedTests, string $zipFilePath, string $internalBaseDir = 'tests'): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->logger?->error("Failed to create or open ZIP archive: {zipFilePath}", ['zipFilePath' => $zipFilePath]);
            return null;
        }

        $internalBaseDir = rtrim($internalBaseDir, DIRECTORY_SEPARATOR);

        foreach ($selectedTests as $index => $testData) {
             if (!isset($testData['code']) || empty($testData['code'])) {
                $this->logger?->warning("Skipping test in ZIP due to empty code at index {index}.", ['index' => $index]);
                continue;
            }
            // Determine filename within ZIP
            $filenameInZip = $testData['filenameHint'] ?? "GeneratedTest" . ($index + 1) . ".php";
             if (!str_ends_with(strtolower($filenameInZip), '.php')) {
                $filenameInZip .= '.php';
            }
            $fullPathInZip = ($internalBaseDir ? $internalBaseDir . DIRECTORY_SEPARATOR : '') . $filenameInZip;

            if (!$zip->addFromString($fullPathInZip, $testData['code'])) {
                $this->logger?->error("Failed to add test to ZIP: {filenameInZip}", ['filenameInZip' => $filenameInZip]);
                // Continue or handle error
            } else {
                 $this->logger?->debug("Added to ZIP: {filenameInZip}", ['filenameInZip' => $filenameInZip]);
            }
        }

        if (!$zip->close()) {
            $this->logger?->error("Failed to close ZIP archive: {zipFilePath}", ['zipFilePath' => $zipFilePath]);
            // Attempt to delete potentially corrupted zip file
            if (file_exists($zipFilePath)) unlink($zipFilePath);
            return null;
        }

        $this->logger?->info("Successfully exported tests to ZIP archive: {zipFilePath}", ['zipFilePath' => $zipFilePath]);
        return $zipFilePath;
    }
}
