<?php

namespace Pipeline;

use Utils\FileHelper;
use Utils\Logger;
use Utils\SocketNotifier;
use ZipArchive;

class Exporter
{
    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier,
        private string $testDir,
        private string $exportDir,
    ) {}

    public function export(string $repoPath, string $testDir, bool $isApi = false): array
    {
        $this->notifier->sendUpdate("Exporting generated tests", 90);

        $baseDir = $isApi ? $_ENV['REPORT_DIR'] . '/exported_test_cases_api' : $_ENV['REPORT_DIR'] . '/exported_test_cases_cli';
        $exportDir = $baseDir . '/' . basename($repoPath) . '_' . date('Y-m-d_H-i-s');

        // Create export directory
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }
        mkdir($exportDir, 0777, true);

        // Grab all the files contained in $testDir
        $testFiles = glob($testDir . '/*.php');
        if (empty($testFiles)) {
            throw new \RuntimeException("No test files found in $testDir");
        }
        
        // loop and copy each test file to the export directory
        foreach ($testFiles as $index => $test) {
            // Copy file
            @copy($test, $exportDir . '/' . basename($test));
        }

        // Create downloadable ZIP archive from the export directory
        $this->logger->info("Exporting test cases", [
            'exportDir' => $exportDir,
            'testFiles' => count($testFiles)
        ]);
        $zipPath = $this->createZipArchive($exportDir, $repoPath, $testFiles);

        $this->notifier->sendUpdate("Test export completed", 95);

        return [
            'exportDir' => $exportDir,
            'zipPath' => $zipPath
        ];
    }

    private function createZipArchive(string $exportDir, string $repoPath, array $selectedTests): string
    {
        $repoName = basename($repoPath);
        $timestamp = date('Y-m-d_H-i-s');
        $zipName = sprintf('test_cases_%s_%s.zip', $repoName, $timestamp);
        $zipPath = dirname($exportDir) . '/' . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create ZIP archive at $zipPath");
        }

        try {
            // Add README file with metadata
            $readmeContent = $this->generateReadme($repoPath, $selectedTests);
            $zip->addFromString('README.md', $readmeContent);

            // Add all test files
            $testFiles = glob($exportDir . '/*.php');
            foreach ($testFiles as $file) {
                $zip->addFile($file, 'tests/' . basename($file));
            }

            // Add PHPUnit configuration
            $phpunitConfig = $this->generatePhpunitConfig();
            $zip->addFromString('phpunit.xml', $phpunitConfig);

            // Add composer.json for test dependencies
            $composerJson = $this->generateComposerJson();
            $zip->addFromString('composer.json', $composerJson);

            $zip->close();

            $this->logger->info("Created ZIP archive", [
                'path' => $zipPath,
                'files' => count($testFiles)
            ]);

            return $zipPath;

        } catch (\Exception $e) {
            $zip->close();
            throw $e;
        }
    }

    private function generateReadme(string $repoPath, array $selectedTests): string
    {
        $vulnerabilityTests = array_filter($selectedTests, fn($test) => $test['type'] === 'vulnerability');
        $mutationTests = array_filter($selectedTests, fn($test) => $test['type'] === 'mutation');
        $totalTest = count($selectedTests);
        $totalVulnTest = count($vulnerabilityTests);
        $totalMutTest = count($mutationTests);
        return <<<MD
# Generated Test Cases

## Overview
- Repository: {$repoPath}
- Generated: {$this->getCurrentTimestamp()}
- Total Test Cases: $totalTest
  - Security Tests: $totalVulnTest
  - Mutation Tests: $totalMutTest

## Structure
```
├── tests/          # Generated test cases
├── phpunit.xml     # PHPUnit configuration
├── composer.json   # Dependencies
└── README.md      # This file
```

## Installation
1. Install dependencies:
   ```bash
   composer install
   ```

2. Run the tests:
   ```bash
   ./vendor/bin/phpunit
   ```

## Test Cases
### Security Tests
{$this->formatTestList($vulnerabilityTests)}

### Mutation Tests
{$this->formatTestList($mutationTests)}

## Notes
- All tests are generated using various LLMs (GPT, Claude, Gemini)
- Tests are designed to catch security vulnerabilities and verify fixes
- Each test includes metadata about its purpose and generation source
MD;
    }

    private function getCurrentTimestamp(): string
    {
        return date('Y-m-d H:i:s T');
    }

    private function formatTestList(array $tests): string
    {
        if (empty($tests)) {
            return "No tests in this category.\n";
        }

        $output = "\n";
        foreach ($tests as $test) {
            $source = $test['source'];
            if ($test['type'] === 'vulnerability') {
                $output .= sprintf("- %s (Rule: %s)\n  %s\n",
                    $test['model'],
                    $source['ruleId'] ?? 'N/A',
                    $source['description'] ?? 'No description'
                );
            } else {
                $output .= sprintf("- %s (Mutator: %s)\n  Line %d in %s\n",
                    $test['model'],
                    $source['mutatorName'] ?? 'N/A',
                    $source['originalStartLine'] ?? 0,
                    basename($source['originalFilePath'] ?? 'unknown')
                );
            }
        }
        return $output;
    }

    private function generatePhpunitConfig(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="./vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Security">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <ini name="error_reporting" value="-1"/>
        <ini name="display_errors" value="On"/>
    </php>
</phpunit>
XML;
    }

    private function generateComposerJson(): string
    {
        return <<<JSON
{
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\\\": "tests/"
        }
    }
}
JSON;
    }
} 