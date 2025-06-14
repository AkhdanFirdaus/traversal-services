<?php

namespace Pipeline;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\ConfigInfection;
use Utils\IStats;
use Utils\Logger;
use Utils\PHPUnitConfig;
use Utils\SocketNotifier;

class InfectionRunner
{
    private string $configPath;
    private Logger $logger;

    public function __construct(private string $projectDir, private string $testDir) {
        $infectionConfig = new ConfigInfection($projectDir, $testDir, 'outputs');
        $infectionConfig->write();
        $this->configPath = $infectionConfig->getConfigPath();
        $this->logger = new Logger();
    }

    public function run(): mixed
    {
        // Run Infection
        $process = new Process([
            'vendor/bin/infection', 
            '--no-interaction', 
            '--configuration=infection.json5'
        ], $this->projectDir);
        
        $process->setTimeout(3600);
        
        $process->run();
        
        if (!$process->isSuccessful()) {
            $this->logger->error('Failed to run infection runner', ['error' => $process->getErrorOutput()]);
            throw new ProcessFailedException($process);
        }
        
        $this->logger->info('Success run infection runner');

        return $this->parseResults();
    }

    public function getReportPath() : string {
        return $this->projectDir . '/outputs/infection.log';
    }

    private function parseResults(): mixed
    {
        $results = [];

        $content = FileHelper::readFile($this->projectDir . '/outputs/infection-report.json');

        if ($content) {
            $report = json_decode($content, true);
            $results = $report['stats'];
        }

        return $results;
    }

    // public function copyTestsToRepo($testCases): void {
    //     // Export each test case
    //     // $this->logger->info("Copying test cases to repository", ['testCount' => count($testCases)]);
    //     foreach ($testCases as $index => $test) {
    //         $filename = FileHelper::saveTestCode($this->testDir, $test['generatedSourceCode']);

    //         // $this->logger->info("Exported test case", [
    //         //     'file' => $filename,
    //         //     'type' => $test['type']
    //         // ]);
    //     }
    // }
} 