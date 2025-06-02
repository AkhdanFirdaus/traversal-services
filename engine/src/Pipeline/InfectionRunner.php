<?php

namespace Pipeline;

use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\Logger;
use Utils\SocketNotifier;

class InfectionRunner
{
    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier,
        private string $repoDir,
        private string $testDir,
        private bool $isFinal = false,
    ) {}

    public function run(): array
    {
        $this->logger->info("Changing directory to target", ['targetDir' => $this->repoDir]);
        
        
        $this->setupPhpUnitConfig();
        $configFile = $this->setupInfectionConfig();
        
        // Run Infection
        $command = ['/app/vendor/bin/infection', '--no-interaction', '--configuration=' . $configFile];

        $this->logger->info("Running Infection command", ['command' => implode(' ', $command)]);
        
        $process = new Process($command);
        $process->setTimeout(3600);
        
        try {
            $this->notifier->sendUpdate("Starting mutation testing", 55);
            $process->run();
            $stdOutput = $process->getOutput();
            $stdError = $process->getErrorOutput();
            $exitCode = $process->getExitCode();
            
            if (!$process->isSuccessful()) {
                throw new \RuntimeException("Infection run failed: " . $stdError);
            }

            $this->logger->info("Infection process completed", [
                'exitCode' => $exitCode,
                'output' => $stdOutput,
                'error' => $stdError
            ]);

            // Parse results
            $results = $this->parseResults($this->repoDir . '/result');

        } catch (\RuntimeException $e) {
            $this->logger->error($e->getMessage(), [
                'exitCode' => $exitCode,
                'output' => $stdOutput,
                'error' => $stdError,
                'trace' => $e->getTraceAsString()
            ]);
        }  finally {
            $this->notifier->sendUpdate("Mutation testing completed", 75);
        }

        return $results;
    }

    private function setupInfectionConfig(): string
    {
        $outputDir = $this->isFinal ? 'mutated_result' : 'result';
        $configFile = $this->isFinal ? 'mutated_infection.json5' : 'infection.json5';
        $excludeDirs = $this->isFinal ? ['vendor', "/^(?!.*Mutate).*\\.php$/"] : ['vendor'];
        $targetPath = $this->repoDir . '/' . $configFile;
        $template = [
            "\$schema" => $this->repoDir . "/vendor/infection/infection/resources/schema.json",
            "bootstrap" => $this->repoDir . "/vendor/autoload.php",
            "phpUnit" => ["configDir" => $this->repoDir],
            "source" => [
                "directories" => [
                    $this->repoDir . "/src", 
                    $this->testDir
                ], 
                "excludes" => $excludeDirs
            ],
            "logs" => [
                "text" => "$outputDir/infection.log",
                "html" => "$outputDir/infection.html",
                "summary" => "$outputDir/summary.log",
                "json" => "$outputDir/infection-report.json",
                "summaryJson" => "$outputDir/summary.json"
            ],
            "mutators" => [
                "@default" => true
            ],
            "testFramework" => "phpunit",
        ];

        if (file_put_contents($targetPath, json_encode($template, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false) {
            $this->logger->info("Infection configuration created", ['filePath' => $targetPath]);
        } else {
            throw new \RuntimeException("Failed to create Infection configuration file");
        }

        return $targetPath;
    }

    private function setupPhpUnitConfig(): string
    {
        $bootstrapPath = $this->repoDir . '/vendor/autoload.php';
        $template = <<<XML
<phpunit bootstrap="{$bootstrapPath}"
         executionOrder="random"
         resolveDependencies="true">
    <testsuites>
        <testsuite name="Path Traversal Tests">
            <directory>{$this->testDir}</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML;

        if (file_put_contents($this->repoDir . '/phpunit.xml', $template) !== false) {
            $this->logger->info("PHPUnit configuration created", ['filePath' => $this->repoDir . '/phpunit.xml']);
        } else {
            throw new \RuntimeException("Failed to create PHPUnit configuration file");
        }
        
        return $this->repoDir . '/phpunit.xml';
    }

    private function parseResults(string $outputDir): array
    {
        $results = [
            'score' => 0,
            'total' => 0,
            'killed' => 0,
            'escaped' => 0,
            'errored' => 0,
            'escapedMutants' => []
        ];

        $content = FileHelper::readFile($outputDir . '/infection-report.json', $this->logger);

        if ($content) {
            $report = json_decode($content, true);
            
            if ($report) {
                $results['total'] = $report['stats']['totalMutantsCount'] ?? 0;
                $results['killed'] = $report['stats']['killedCount'] ?? 0;
                $results['escaped'] = $report['stats']['escapedCount'] ?? 0;
                $results['errored'] = $report['stats']['errorCount'] ?? 0;
                $results['score'] = $report['stats']['msi'] ?? 0;
                
                // Extract escaped mutants details
                if (isset($report['escaped'])) {
                    foreach ($report['escaped'] as $mutant) {
                        array_push($results['escapedMutants'], $mutant['mutator']);
                    }
                }

                return $results;
            }
        }

        return $results;
    }

    public function copyTestsToRepo($testCases): void {
        // Export each test case
        foreach ($testCases as $index => $test) {
            $ori = basename($test['originalFilePath']);

            $filename = FileHelper::saveTestCode($ori, $this->testDir, $test['generatedSourceCode']);

            $this->logger->info("Exported test case", [
                'file' => $filename,
                'type' => $test['type']
            ]);
        }
    }

    public function setFinalRunner(bool $isFinal): void
    {
        $this->isFinal = $isFinal;
        $this->logger->info("Setting final runner mode", ['isFinal' => $isFinal]);
    }

    public function getMutatedTestDirectory(): string
    {
        return $this->repoDir . '/mutated_tests';
    }
} 