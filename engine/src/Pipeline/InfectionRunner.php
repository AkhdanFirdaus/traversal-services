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
        private SocketNotifier $notifier
    ) {}

    public function run(string $targetDir): array
    {
        $this->logger->info("Changing directory to target", ['targetDir' => $targetDir]);
        
        $currentDir = getcwd();
        chdir($targetDir);

        $this->setupPhpUnitConfig($targetDir);
        $configFile = $this->setupInfectionConfig($targetDir);
        
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
            $results = $this->parseResults('/app/' . $targetDir . '/result');

        } catch (\RuntimeException $e) {
            $this->logger->error($e->getMessage(), [
                'exitCode' => $exitCode,
                'output' => $stdOutput,
                'error' => $stdError,
                'trace' => $e->getTraceAsString()
            ]);
        }  finally {
            chdir($currentDir);
        }

        $this->notifier->sendUpdate("Mutation testing completed", 75);

        return $results;
    }

    private function setupInfectionConfig(string $dir): string
    {
        $basepath = '/app/' . $dir;
        $template = [
            "\$schema" => $basepath . "/vendor/infection/infection/resources/schema.json",
            "bootstrap" => $basepath . "/vendor/autoload.php",
            "phpUnit" => ["configDir" => $basepath],
            "source" => [
                "directories" => [
                    $basepath . "/src", 
                    $basepath ."/tests"
                ], 
                "excludes" => ["vendor"]
            ],
            "logs" => [
                "text" => "result/infection.log",
                "html" => "result/infection.html",
                "summary" => "result/summary.log",
                "json" => "result/infection-report.json",
                "summaryJson" => "result/summary.json"
            ],
            "mutators" => [
                "@default" => true
            ],
            "testFramework" => "phpunit",
        ];

        if (file_put_contents($basepath . '/infection.json5', json_encode($template, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false) {
            $this->logger->info("Infection configuration created", ['filePath' => $basepath . '/infection.json5']);
        } else {
            throw new \RuntimeException("Failed to create Infection configuration file");
        }

        return $basepath .'/infection.json5';
    }

    private function setupPhpUnitConfig($dir): string
    {
        $basepath = '/app/' . $dir;
        $bootstrapPath = $basepath . '/vendor/autoload.php';
        $testdir = $basepath . '/tests';
        $template = <<<XML
<phpunit bootstrap="{$bootstrapPath}"
         executionOrder="random"
         resolveDependencies="true">
    <testsuites>
        <testsuite name="Path Traversal Tests">
            <directory>{$testdir}</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML;
        if (file_put_contents($basepath . '/phpunit.xml', $template) !== false) {
            $this->logger->info("PHPUnit configuration created", ['filePath' => $basepath . '/phpunit.xml']);
        } else {
            throw new \RuntimeException("Failed to create PHPUnit configuration file");
        }
        
        return $basepath . '/phpunit.xml';
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
                    foreach ($report['escaped'] as $mutantParent) {
                        $mutant = $mutantParent['mutator'];
                        array_push($results['escapedMutants'], [
                            'file' => $mutant['originalFilePath'],
                            'line' => $mutant['originalStartLine'],
                            'mutator' => $mutant['mutatorName'],
                            'originalSourceCode' => $mutant['originalSourceCode'],
                            'mutatedSourceCode' => $mutant['mutatedSourceCode'],
                        ]);
                    }
                }

                return $results;
            }
        }

        return $results;
    }
} 