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
use Utils\ReportParser;

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
            // =================================================================
            // Step 1: Setup & Initial Baseline Run
            // =================================================================
            
            $this->logger->info("Starting repository processing", ['repo' => $repoUrl, 'taskId' => $roomName]);
            
            $cloner = new RepositoryCloner($repoUrl, $roomName);
            $cloner->run();

            $projectDir = $cloner->getTempDirectory();

            $outputDir = '/app/outputs' . DIRECTORY_SEPARATOR . basename($projectDir);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $infectionRunner = new InfectionRunner($projectDir, 'tests', $outputDir, $this->logger);
            
            // Run Infection ONCE to get our master "to-do list"
            $this->logger->info("Establishing initial project baseline with a single Infection run...");
            $initialMutationReportContent = $infectionRunner->run();
            $infectionRunner->saveReport('msi-initial.json');
            $this->logger->info("Initial baseline established.");

            // Parse the report to get a structured list of targets
            $initialReportSummary = ReportParser::generateMutationSummary($initialMutationReportContent, $projectDir);
            $analysisTargets = json_decode($initialReportSummary, true)['survivingMutantsByFile'] ?? [];

            if (empty($analysisTargets)) {
                $this->logger->info("No surviving mutants found in the initial report. Nothing to do. Exiting.");
                // You can add final export logic here if needed
                return [];
            }

            // =================================================================
            // Step 2: Simplified "MSI-Oriented" Generation Loop
            // =================================================================
            $generator = new AiTestGenerator($projectDir, $outputDir, $this->logger);
            
            $iteration = 1;
            // Loop through each file that has surviving mutants
            foreach (array_keys($analysisTargets) as $fileToFix) {
                $this->logger->info("Starting AI Generation Iteration #{$iteration}", [
                    'target_file' => $fileToFix
                ]);

                try {
                    // Create a highly specific analysis result for the generator
                    $specificAnalysis = [[
                        'file' => $fileToFix,
                        'reason' => 'This file has surviving mutants that need to be killed.',
                        'details' => $analysisTargets[$fileToFix] // Provide specific mutant details
                    ]];

                    // Generate AND self-validate code for this specific target.
                    // This method now contains the internal "test-and-fix" loop.
                    $generatedFiles = $generator->generateTestCase(
                        $specificAnalysis,
                        $iteration
                    );
                    
                    if (empty($generatedFiles)) {
                        $this->logger->warning("Generation phase produced no valid files for {$fileToFix}. Proceeding to next target.");
                        continue;
                    }

                    // Integrate the validated files into the project
                    $generator->rewriteCode($generatedFiles);
                    
                } catch (\Throwable $th) {
                    $this->logger->error("Iteration #{$iteration} failed for target {$fileToFix}.", [
                        'stack' => $th->getTraceAsString(),
                        'error' => $th->getMessage(),
                    ]);
                    continue; 
                }
                $iteration++;
            }
            
            // =================================================================
            // Step 3: Final Validation and Export
            // =================================================================
            $this->logger->info("All generation attempts are complete. Running final validation...");
            $finalMutationReportContent = $infectionRunner->run();
            $infectionRunner->saveReport('msi-final.json');
            $this->logger->info("Final MSI score calculated.");

            $exportPath = $projectDir . DIRECTORY_SEPARATOR . 'tests';
            $exporter = new Exporter($this->logger, $this->notifier, $exportPath, $outputDir);
            
            $exporter->run($iteration);
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