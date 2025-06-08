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

            // Step 2: PHP Unit Analysis
            $phpUnitRunner = new PhpUnitRunner(
                $projectDir, 
                'tests', 
                'outputs',
            );
            $unitResult = $phpUnitRunner->run();

            // Step 3: Initial Infection Run
            $infectionRunner = new InfectionRunner(
                $projectDir, 
                'tests', 
                'outputs',
            );
            $initialMsi = $infectionRunner->run();

            // Step 4: Generate AI Test Cases
            // $generator = new AiTestGenerator($projectDir);
            // $generator->analyzeSystems(
            //     $projectDir . DIRECTORY_SEPARATOR . 'phpunit.xml', 
            //     $projectDir . DIRECTORY_SEPARATOR . 'outputs' . DIRECTORY_SEPARATOR .'infection-report.json'
            // );
            // $testCases = $generator->generate($vulnerabilities, $initialMsi['escapedMutants']);

            // // Step 5: Select and Export Tests
            // $selector = new TestSelector($this->logger);
            // $selectedTests = $selector->select($testCases);
            
            // // Step 6: Final Infection Run
            // $infectionRunner->copyTestsToRepo($testCases);
            // $infectionRunner->setFinalRunner(true);
            // $finalMsi = $infectionRunner->run();
            
            // // Step 7: Export Tests
            // $exporter = new Exporter(
            //     $this->logger, 
            //     $this->notifier,
            //     $analyzer->getProjectDir(), 
            //     $analyzer->getDetectedTestDir(),
            // );
            
            // $exportResult = $exporter->export(
            //     $analyzer->getProjectDir(), 
            //     $analyzer->getDetectedTestDir(), 
            //     $isApi,
            // );

            // // Step 8: Generate Reports
            // $reporter = new Reporter($this->logger, $this->notifier);
            // $reports = $reporter->generateReports(
            //     $analyzer->getProjectDir(),
            //     $vulnerabilities,
            //     $initialMsi,
            //     $finalMsi,
            //     $exportResult['exportDir'],
            //     $isApi,
            // );

            // // // Step 9: Cleanup
            // // $cleaner = new Cleaner($this->logger, $this->notifier);
            // // $cleaner->cleanup($repoPath, $isApi);

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