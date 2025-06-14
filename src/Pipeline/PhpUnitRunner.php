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
        $phpUnitConfig = new ConfigPHPUnit($projectDir, $testDir, 'outputs');
        $phpUnitConfig->write();
        $this->configPath = $phpUnitConfig->getConfigPath();
        $this->logger = new Logger();
    }

    public function run(): mixed {
        $process = new Process([
            'vendor/bin/phpunit', 
            '--coverage-xml', 
            'outputs', 
            '--log-junit', 
            'outputs/junit.xml',
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
            'coverage' => FileHelper::readFile($this->projectDir . '/outputs/index.xml'),
            'junit' => FileHelper::readFile($this->projectDir . '/outputs/junit.xml'),
        ];
    }

    public function getReportsPath(): array {
        $directory = $this-> projectDir . DIRECTORY_SEPARATOR . 'outputs';

        $files = array_filter(scandir($directory), function ($file) use ($directory) {
            return is_file("$directory/$file") && pathinfo($file, PATHINFO_EXTENSION) === 'xml';
        });

        return array_map(fn($file) => "$this->projectDir/outputs/$file", array_values($files));
    }
}