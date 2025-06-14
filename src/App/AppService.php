<?php

namespace App;

use Pipeline\RepositoryCloner;
use Pipeline\HeuristicAnalyzer;
use Pipeline\InfectionRunner;
use Pipeline\AiTestGenerator;
use Pipeline\TestSelector;
use Pipeline\Exporter;
use Pipeline\Reporter;
use Pipeline\Cleaner;
use Utils\Logger;
use Utils\SocketNotifier;
use Dotenv\Dotenv;
use Pipeline\Analyzer;
use Pipeline\PhpUnitRunner;
use Utils\FileHelper;

class AppService
{
    private string $taskId;

    public function __construct(private Logger $logger, private SocketNotifier $notifier)
    {
        $this->loadEnvironment();
        $this->taskId = uniqid('task_', true);
    }

    private function loadEnvironment(): void
    {
        try {
            $dotenv = Dotenv::createImmutable('/app/');
            $dotenv->load();
        } catch (\Dotenv\Exception\InvalidPathException $e) {
            $this->logger->warning(".env file not found. Relying on system environment variables or defaults", [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function handleProcessRepo(string $repoUrl): array
    {
        try {
            // Step 1: Clone Repository
            $cloner = new RepositoryCloner($repoUrl);
            $this->logger->info("Starting repository processing", ['repo' => $repoUrl, 'taskId' => $this->taskId]);
            // $this->notifier->sendUpdate("Starting repository processing", 0);

            $cloner->run();
            $projectDir = $cloner->getTempDirectory();

            $outputDir = '/app/outputs' . DIRECTORY_SEPARATOR . basename($projectDir);
            mkdir($outputDir, 0755, true);

            // Step 2: Initial PHP Unit Analysis and Infection Run
            $phpUnitRunner = new PhpUnitRunner(
                $projectDir, 
                'tests', 
                $outputDir,
                $this->logger,
            );
            
            $infectionRunner = new InfectionRunner(
                $projectDir, 
                'tests', 
                $this->logger,
            );
            
            $unitResult = $phpUnitRunner->run();
            $initialMsi = $infectionRunner->run();

            file_put_contents($outputDir . DIRECTORY_SEPARATOR . 'msi-initial.json', json_encode($initialMsi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Step 3: Generate AI Test Cases
            $generator = new AiTestGenerator(
                $projectDir, 
                $outputDir, 
                $this->logger,
            );
            
            for ($i=1; $i <= 5; $i++) { 
                $this->logger->info("Iteration-$i");

                $analyzerResultPath = $generator->analyzeSystems(
                    $phpUnitRunner->getReportsPath(),
                    $infectionRunner->getReportPath(),
                    $i,
                );
                
                $generatedResultPath = $generator->generateTestCase(
                    $analyzerResultPath,
                    $i
                );
                
                $exportPath = $generator->rewriteCode($generatedResultPath);
                
                // Step 4: Final PHP Unit Analysis and Infection Run
                $unitRes = $phpUnitRunner->run();
                $msiRes = $infectionRunner->run();
                
                file_put_contents($outputDir . DIRECTORY_SEPARATOR . "msi-$i.json", json_encode($msiRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            // Step 5: Export Tests
            $exporter = new Exporter(
                $this->logger, 
                $this->notifier,
                $exportPath,
                $outputDir
            );

            $exporter->run();

            // // Return final results
            // $results = [
            //     'taskId' => $this->taskId,
            //     'repoUrl' => $repoUrl,
            //     'vulnerabilities' => $vulnerabilities,
            //     'initialMsi' => $initialMsi,
            //     'finalMsi' => $finalMsi,
            //     'exportedTestsPath' => $exportResult['exportDir'],
            //     'downloadableZip' => $exportResult['zipPath'],
            //     'reports' => $reports
            // ];

            // $this->logger->info("Repository processing completed successfully", $results);
            // $this->notifier->sendUpdate("Processing completed", 100);

            return [];

        } catch (\Exception $e) {
            $this->logger->error("Error processing repository", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
} 