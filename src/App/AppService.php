<?php

namespace App;

use Pipeline\RepositoryCloner;
use Pipeline\InfectionRunner;
use Pipeline\AiTestGenerator;
use Pipeline\Exporter;
use Utils\Logger;
use Utils\SocketNotifier;
use Dotenv\Dotenv;
use Pipeline\PhpUnitRunner;
use Utils\FileHelper;

class AppService
{
    public function __construct(private Logger $logger, private SocketNotifier $notifier)
    {
        $this->loadEnvironment();
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

    public function handleProcessRepo(string $repoUrl, string $roomName): array
    {
        try {
            // Step 1: Clone Repository
            $this->logger->info("Starting repository processing", ['repo' => $repoUrl, 'taskId' => $roomName]);
            // $this->notifier->sendUpdate("Starting repository processing", 0);
            
            $cloner = new RepositoryCloner($repoUrl, $roomName);
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
                $outputDir,
                $this->logger,
            );
            
            $initialUnit = $phpUnitRunner->run();
            $phpUnitRunner->saveReport('phpunit-initial.json');

            $initialMsi = $infectionRunner->run();
            $infectionRunner->saveReport('msi-initial.json');

            // Step 3: Generate AI Test Cases
            $generator = new AiTestGenerator(
                $projectDir, 
                $outputDir, 
                $this->logger,
            );
            
            $this->logger->info('AIGenerator: Preparing to Generate Test Case using Function Calling...');
            
            $unitResults = $initialUnit;
            $mutationReport = $initialMsi;
            
            for ($i=1; $i <= 20; $i++) { 
                try {
                    $this->logger->info("Iteration-$i");

                    $projectStructure = FileHelper::getProjectStructure($this->logger, $projectDir, $outputDir, $i);

                    $analizeSystem = $generator->analyzeSystems(
                        $projectStructure,
                        $unitResults,
                        $mutationReport,
                        $i
                    );
                    
                    $generatedResult = $generator->generateTestCase(
                        $analizeSystem,
                        $i
                    );
                    
                    $exportPath = $generator->rewriteCode($generatedResult);

                    $unitRes = $phpUnitRunner->run([
                        "--process-isolation",
                    ]);
                    $phpUnitRunner->saveReport("phpunit-$i.json");
                    
                    $msiRes = $infectionRunner->run();
                    $infectionRunner->saveReport("msi-$i.json");

                    $unitResults = $unitRes;
                    $mutationReport = $msiRes;
                    
                } catch (\Throwable $th) {
                    $this->logger->error("Iteration-$i failed to generate", [
                        'stack' => $th->getTraceAsString(),
                        'error' => $th->getMessage(),
                    ]);
                    continue;
                }
            }
            
            // // Step 4: Final PHP Unit Analysis and Infection Run
            
            // // Step 5: Export Tests
            $exporter = new Exporter(
                $this->logger, 
                $this->notifier,
                $exportPath,
                $outputDir
            );
            
            $exporter->run($i);
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