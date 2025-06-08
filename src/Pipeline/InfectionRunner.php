<?php

namespace Pipeline;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\InfectionConfig;
use Utils\IStats;
use Utils\Logger;
use Utils\PHPUnitConfig;
use Utils\SocketNotifier;

class InfectionRunner
{
    private string $configPath;

    public function __construct(private string $projectDir, private string $testDir, private string $outputDir) {
        $infectionConfig = new InfectionConfig($projectDir, $testDir, $outputDir = 'result ');
        $infectionConfig->write();
        $this->configPath = $infectionConfig->getConfigPath();
    }

    public function run(): void
    {
        // Run Infection
        $command = ['/vendor/bin/infection', '--no-interaction', '--configuration=' . $this->configPath];

        $process = new Process($command);
        $process->setTimeout(3600);
        
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // Parse results
        $results = $this->parseResults($this->projectDir . DIRECTORY_SEPARATOR . $this->outputDir);
    }

    private function parseResults(string $outputDir): IStats
    {
        $results = [];

        $content = FileHelper::readFile($outputDir . DIRECTORY_SEPARATOR . 'infection-report.json');

        if ($content) {
            $report = json_decode($content, true);
            $results = new IStats($report['stats']);
        }

        return $results;
    }

    public function copyTestsToRepo($testCases): void {
        // Export each test case
        // $this->logger->info("Copying test cases to repository", ['testCount' => count($testCases)]);
        foreach ($testCases as $index => $test) {
            $filename = FileHelper::saveTestCode($this->testDir, $test['generatedSourceCode']);

            // $this->logger->info("Exported test case", [
            //     'file' => $filename,
            //     'type' => $test['type']
            // ]);
        }
    }
} 