<?php

namespace Pipeline;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\ConfigPHPUnit;
use Utils\Logger;

class PhpUnitRunner {
    private string $configPath;
    private Logger $logger;

    public function __construct(private string $projectDir, private string $testDir, private string $outputDir) {
        $phpUnitConfig = new ConfigPHPUnit($projectDir, $testDir, $outputDir);
        $phpUnitConfig->write();
        $this->configPath = $phpUnitConfig->getConfigPath();
        $this->logger = new Logger();
    }

    public function run(): mixed {
        $process = new Process([
            '/app/vendor/bin/phpunit', 
            '--coverage-xml', 
            $this->outputDir, 
            '--log-junit', 
            $this->outputDir . DIRECTORY_SEPARATOR . 'junit.xml',
            '--coverage-filter',
            'src'
        ], $this->projectDir);
        
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->error('Failed run phpunit', ['error' => $process->getErrorOutput()]);
            throw new ProcessFailedException($process);
        }

        $this->logger->info('Success run phpunit');

        return [
            'coverage' => FileHelper::readFile($this->projectDir . DIRECTORY_SEPARATOR . $this->outputDir . DIRECTORY_SEPARATOR . 'index.xml'),
            'junit' => FileHelper::readFile($this->projectDir . DIRECTORY_SEPARATOR . $this->outputDir . DIRECTORY_SEPARATOR . 'junit.xml'),
        ];
    }
}