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

class AppService
{
    private Logger $logger;
    private SocketNotifier $notifier;
    private string $taskId;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->notifier = new SocketNotifier();
        $this->loadEnvironment();
        $this->taskId = uniqid('task_', true);
    }

    private function loadEnvironment(): void
    {
        try {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
            $dotenv->load();
        } catch (\Dotenv\Exception\InvalidPathException $e) {
            $this->logger->warning(".env file not found. Relying on system environment variables or defaults", [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function handleProcessRepo(string $repoUrl, bool $isApi = false): array
    {
        try {
            $this->logger->info("Starting repository processing", ['repo' => $repoUrl, 'taskId' => $this->taskId]);
            $this->notifier->sendUpdate("Starting repository processing", 0);

            // Step 1: Clone Repository
            $cloner = new RepositoryCloner($this->logger, $this->notifier);
            $repoPath = $cloner->clone($repoUrl, $isApi);

            // Step 2: Heuristic Analysis
            $analyzer = new HeuristicAnalyzer($this->logger, $this->notifier, $repoPath);
            $vulnerabilities = $analyzer->analyze($repoPath);

            // Step 3: Initial Infection Run
            $infectionRunner = new InfectionRunner(
                $this->logger, 
                $this->notifier, 
                $analyzer->getProperRepoPath(), 
                $analyzer->getDetectedTestDirectory(),
            );
            $initialMsi = $infectionRunner->run();

            // Step 4: Generate AI Test Cases
            $generator = new AiTestGenerator($this->logger, $this->notifier, $analyzer->getProperRepoPath());
            $testCases = $generator->generate($vulnerabilities, $initialMsi['escapedMutants']);

            // // Step 5: Select and Export Tests
            // $selector = new TestSelector($this->logger);
            // $selectedTests = $selector->select($testCases);
            
            // Step 6: Final Infection Run
            $infectionRunner->copyTestsToRepo($testCases);
            $infectionRunner->setFinalRunner(true);
            $finalMsi = $infectionRunner->run();
            
            // Step 7: Export Tests
            $exporter = new Exporter(
                $this->logger, 
                $this->notifier,
                $analyzer->getProperRepoPath(), 
                $analyzer->getDetectedTestDirectory(),
            );
            $exportResult = $exporter->export($testCases, $analyzer->getProperRepoPath(), $isApi);

            // Step 8: Generate Reports
            $reporter = new Reporter($this->logger, $this->notifier);
            $reports = $reporter->generateReports(
                $analyzer->getProperRepoPath(),
                $vulnerabilities,
                $initialMsi,
                $finalMsi,
                $exportResult['exportDir'],
                $isApi
            );

            // // Step 9: Cleanup
            // $cleaner = new Cleaner($this->logger, $this->notifier);
            // $cleaner->cleanup($repoPath, $isApi);

            // Return final results
            $results = [
                'taskId' => $this->taskId,
                'repoUrl' => $repoUrl,
                'vulnerabilities' => $vulnerabilities,
                'initialMsi' => $initialMsi,
                'finalMsi' => $finalMsi,
                'exportedTestsPath' => $exportResult['exportDir'],
                'downloadableZip' => $exportResult['zipPath'],
                'reports' => $reports
            ];

            $this->logger->info("Repository processing completed successfully", $results);
            $this->notifier->sendUpdate("Processing completed", 100);

            return $results;

        } catch (\Exception $e) {
            $this->logger->error("Error processing repository", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->notifier->sendUpdate("Error: " . $e->getMessage(), -1);
            throw $e;
        }
    }
} 