<?php

namespace Pipeline;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\ConfigInfection;
use Utils\IStats;
use Utils\Logger;
use Utils\PHPUnitConfig;
use Utils\ReportParser;
use Utils\SocketNotifier;

class InfectionRunner
{
    private string $configPath;
    private string $content;

    public function __construct(private string $projectDir, private string $testDir, private string $outputDir, private Logger $logger) {
        $infectionConfig = new ConfigInfection($projectDir, $testDir, 'outputs');
        $infectionConfig->write();
        $this->configPath = $infectionConfig->getConfigPath();
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

        $this->content = FileHelper::readFile($this->projectDir . '/outputs/infection-report.json');
        
        return $this->content;
    }

    public function getReportPath() : string {
        return $this->projectDir . '/outputs/infection-report.json';
    }

    public function saveReport($filename, $mode = ''): void
    {
        $report = '';
        switch ($mode) {
            case 'summary':
                $report = ReportParser::generateMutationSummary($this->content, $this->projectDir);
                break;
            case 'exclude-killed':
                $report = ReportParser::excludingKilled($this->content);
                break;
            default:
                $report = $this->content;
                break;
        }
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . $filename, $report);
    }
} 