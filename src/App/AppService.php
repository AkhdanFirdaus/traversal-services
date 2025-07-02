<?php

namespace App;

use Pipeline\RepositoryCloner;
use Pipeline\InfectionRunner;
use Pipeline\AiTestGenerator;
use Utils\Logger;
use Dotenv\Dotenv;
use Pipeline\PhpUnitRunner;
use Pipeline\Reporter;
use Utils\FileHelper;
use Utils\PromptBuilder;
use Utils\ReportParser;

class AppService
{
    public function __construct(private Logger $logger)
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

    public function handleProcessRepo(string $gitUrl, string $roomName): array
    {
        try {
            // =================================================================
            // Step 1: Setup & Initial Baseline Run
            // =================================================================

            $this->logger->info("Starting repository processing", ['gitUrl' => $gitUrl, 'roomName' => $roomName]);

            $cloner = new RepositoryCloner($gitUrl, $roomName);
            $cloner->run();

            $projectDir = $cloner->getTempDirectory();

            $outputDir = '/app/outputs' . DIRECTORY_SEPARATOR . basename($projectDir);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $phpUnitRunner = new PhpUnitRunner(
                $projectDir,
                'tests',
                $outputDir,
                $this->logger,
            );

            $initialUnit = $phpUnitRunner->run();
            $phpUnitRunner->saveReport('phpunit-initial.json');

            $infectionRunner = new InfectionRunner($projectDir, 'tests', $outputDir, $this->logger);

            $this->logger->info("Establishing initial project baseline with a single Infection run...");
            $initialMutationReportContent = $infectionRunner->run();
            $infectionRunner->saveReport('msi-original.json');
            $infectionRunner->saveReport('msi-initial.json', 'summary');
            $this->logger->info("Initial baseline established.");


            $msiReportWithoutKilled = ReportParser::excludingKilled($initialMutationReportContent);
            $msiReportInitialSummary = ReportParser::generateMutationSummary($initialMutationReportContent, $projectDir);
            $analysisTargets = json_decode($msiReportInitialSummary, true)['survivingMutantsByFile'] ?? [];

            if (empty($analysisTargets)) {
                $this->logger->info("No surviving mutants found in the initial report. Nothing to do. Exiting.");
                return [];
            }

            // =================================================================
            // Step 2: "MSI-Oriented" Generation Loop
            // =================================================================
            $generator = new AiTestGenerator($projectDir, $outputDir, $this->logger);

            $projectStructure = FileHelper::getProjectStructure($this->logger, $projectDir, $outputDir);

            $iteration = 1;
            foreach (array_keys($analysisTargets) as $fileToFix) {
                $this->logger->info("Starting AI Generation Iteration #{$iteration}", [
                    'target_file' => $fileToFix
                ]);


                try {
                    $specificAnalysis = [
                        'file' => $fileToFix,
                        'reason' => 'This file has surviving mutants that need to be killed.',
                        'details' => $analysisTargets[$fileToFix] // Provide specific mutant details
                    ];

                    $instruction = PromptBuilder::instruction();

                    $context = PromptBuilder::generateContext(
                        $projectStructure,
                        $initialUnit,
                        $msiReportWithoutKilled,
                    );

                    $target = PromptBuilder::generateTarget($specificAnalysis);

                    $generatedFiles = $generator->generateTestCase(
                        $instruction,
                        $context,
                        $target,
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
            $infectionRunner->saveReport('msi-final.json', 'summary');
            $this->logger->info("Final MSI score calculated.");

            $reporter = new Reporter($this->logger, $outputDir);
            $results = $reporter->run($initialMutationReportContent, $finalMutationReportContent);
            $reporter->save($results, 'msi-final-report.json');

            $this->logger->info("Repository processing completed successfully", $results);

            return $results;
        } catch (\Exception $e) {
            $this->logger->error("Error processing repository", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            $cloner->deleteTempDirectory();
            $this->logger->disconnect();
        }
    }
}
