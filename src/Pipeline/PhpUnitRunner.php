<?php

namespace Pipeline;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\PHPUnitConfig;

class PhpUnitRunner {
    private string $configPath;

    public function __construct(private string $projectDir, private string $testDir, private string $outputDir) {
        $phpUnitConfig = new PHPUnitConfig($projectDir, $testDir, $outputDir);
        $phpUnitConfig->write();
        $this->configPath = $phpUnitConfig->getConfigPath();
    }

    public function run() {
        $process = new Process([
            '/app/vendor/bin/phpunit', 
            '--coverage-xml', 
            'coverage', 
            '--log-junit', 
            'junit.xml'
        ]);
        
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return [
            'coverage' => FileHelper::readFile($this->projectDir . '/coverage/index.xml'),
            'junit' => FileHelper::readFile($this->projectDir . 'junit.xml'),
        ];
    }
}