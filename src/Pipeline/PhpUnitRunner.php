<?php

namespace Pipeline;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\ConfigPHPUnit;
use Utils\Logger;

class PhpUnitRunner {
    private string $configPath;
    private mixed $content;
    
    public function __construct(private string $projectDir, private string $testDir, private string $outputDir, private Logger $logger) {
        $phpUnitConfig = new ConfigPHPUnit($projectDir, $testDir, 'outputs');
        $phpUnitConfig->write();
        $this->configPath = $phpUnitConfig->getConfigPath();
    }

    public function run($additionalcommands = ['--coverage-xml', 'outputs', '--log-junit', 'outputs/junit.xml', '--coverage-filter', 'src',]): mixed {
        try {
            $process = new Process([
                'vendor/bin/phpunit', 
                ...$additionalcommands,
            ], $this->projectDir);
            
            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger->error('Failed run phpunit', ['error' => $process->getErrorOutput()]);
                throw new ProcessFailedException($process);
            }

            $this->logger->info('Success run phpunit');

            
            $content = [];
            
            foreach ($this->getReportsPath() as $unit) {
                $content[basename($unit)] = FileHelper::readFile($unit);
            }

            $this->content = $content;

            return $content;
        } catch (\Throwable $th) {
            $this->logger->error('Failed run phpunit', [
                'stack' => $th->getTraceAsString(),
                'error' => $th->getMessage(),
            ]);
            throw $th;
        }
    }

    public function getReportsPath(): array {
        $directory = $this-> projectDir . DIRECTORY_SEPARATOR . 'outputs';

        $files = array_filter(scandir($directory), function ($file) use ($directory) {
            return is_file("$directory/$file") && pathinfo($file, PATHINFO_EXTENSION) === 'xml';
        });

        return array_map(fn($file) => "$this->projectDir/outputs/$file", array_values($files));
    }

    public function saveReport(string $filename) : void{
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . $filename, json_encode($this->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}