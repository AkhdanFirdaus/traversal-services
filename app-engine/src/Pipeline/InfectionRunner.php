<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Utils\Logger;
use App\Utils\FileHelper;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class InfectionRunner
{
    private ?Logger $logger;
    private string $defaultInfectionConfigName = 'infection.json';
    private string $infectionJsonDistName = 'infection.json.dist'; // Default name for template

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Runs Infection mutation testing on a given project directory.
     *
     * @param string $projectPath Path to the project where Infection should be run.
     * @param string|null $infectionExecutablePath Path to the Infection executable (e.g., vendor/bin/infection).
     * If null, it tries to find it in common locations.
     * @param array $options Additional options for Infection (e.g., ['--min-msi=70', '--threads=2']).
     * @return array|null An array containing MSI, covered MSI, and paths to log files, or null on failure.
     * Example: ['msi' => 85.5, 'covered_msi' => 90.0, 'log_path' => '...', 'summary_path' => '...']
     */
    public function run(string $projectPath, ?string $infectionExecutablePath = null, array $options = []): ?array
    {
        $this->logger?->info("Attempting to run Infection in project: {projectPath}", ['projectPath' => $projectPath]);

        $executable = $infectionExecutablePath ?? $this->findInfectionExecutable($projectPath);
        if (!$executable) {
            $this->logger?->error("Infection executable not found in project: {projectPath}", ['projectPath' => $projectPath]);
            return null;
        }

        $this->ensureInfectionConfigExists($projectPath);

        $command = [$executable];
        $command = array_merge($command, $options);
        // Ensure logs are generated for parsing
        $command[] = '--logger-json=infection-report.json'; // Standard JSON log for detailed info
        $command[] = '--log-verbosity=default'; // Ensure summary is parsable

        // Define paths for logs relative to the project path for easier cleanup/access
        $jsonLogPath = $projectPath . DIRECTORY_SEPARATOR . 'infection-report.json';
        $textLogPath = $projectPath . DIRECTORY_SEPARATOR . 'infection.log'; // Infection default text log

        // Remove old log files if they exist to avoid confusion
        if (file_exists($jsonLogPath)) unlink($jsonLogPath);
        if (file_exists($textLogPath)) unlink($textLogPath);


        $this->logger?->info("Executing Infection command: {command}", ['command' => implode(' ', $command)]);
        $process = new Process($command, $projectPath); // Run from the project's root
        $process->setTimeout(1800); // 30 minutes timeout for Infection, adjust as needed

        try {
            $process->run(); // Use run() instead of mustRun() to handle non-zero exit codes gracefully
                            // Infection might exit non-zero if MSI is below threshold, but we still want the report.

            $stdOutput = $process->getOutput();
            $stdError = $process->getErrorOutput();

            $this->logger?->debug("Infection STDOUT:\n{stdout}", ['stdout' => $stdOutput]);
            if (!empty($stdError)) {
                $this->logger?->warning("Infection STDERR:\n{stderr}", ['stderr' => $stdError]);
            }

            if (!$process->isSuccessful() && $process->getExitCode() !== 0) {
                 // Infection exit codes: 0 = success, 1 = error, 2 = killed mutants below threshold
                 // We are interested in results even if MSI is low.
                 $this->logger?->warning("Infection process exited with code {exitCode}. Output might still be available.", ['exitCode' => $process->getExitCode()]);
            }

            // Try to parse MSI from the text log or JSON report
            $msiResults = $this->parseMsiFromLogs($textLogPath, $jsonLogPath);

            if ($msiResults === null) {
                $this->logger?->error("Failed to parse MSI from Infection logs for project: {projectPath}", ['projectPath' => $projectPath]);
                // return null; // Decide if this is a hard failure
            }
            
            $this->logger?->info("Infection run completed for {projectPath}. MSI: {msi}, Covered MSI: {covered_msi}", [
                'projectPath' => $projectPath,
                'msi' => $msiResults['msi'] ?? 'N/A',
                'covered_msi' => $msiResults['covered_msi'] ?? 'N/A',
            ]);

            return [
                'msi' => $msiResults['msi'] ?? null,
                'covered_msi' => $msiResults['covered_msi'] ?? null,
                'json_report_path' => file_exists($jsonLogPath) ? $jsonLogPath : null,
                'text_log_path' => file_exists($textLogPath) ? $textLogPath : null,
                'stdout' => $stdOutput,
                'stderr' => $stdError,
                'exit_code' => $process->getExitCode()
            ];

        } catch (ProcessFailedException $exception) { // Should not be hit if using run()
            $this->logger?->error("Infection process failed for project: {projectPath}. Error: {errorMessage}", [
                'projectPath' => $projectPath,
                'errorMessage' => $exception->getMessage()
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->logger?->error("An unexpected error occurred while running Infection for {projectPath}: {errorMessage}", [
                'projectPath' => $projectPath,
                'errorMessage' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function findInfectionExecutable(string $projectPath): ?string
    {
        $commonPaths = [
            $projectPath . '/vendor/bin/infection', // Local to project
            'vendor/bin/infection',                 // If run from main project root with shared vendor
            // Add global composer bin path if needed, though less reliable
        ];
        foreach ($commonPaths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        // Fallback: check if 'infection' is in PATH (less preferred for hermeticity)
        $process = new Process(['command', '-v', 'infection']); // or 'where infection' on Windows
        try {
            $process->run();
            if ($process->isSuccessful()) {
                $pathFromCommand = trim($process->getOutput());
                if (is_executable($pathFromCommand)) {
                    return $pathFromCommand;
                }
            }
        } catch (\Exception $e) {
            // Ignore if 'command -v' fails
        }
        return null;
    }

    private function ensureInfectionConfigExists(string $projectPath): void
    {
        $projectConfigPath = $projectPath . DIRECTORY_SEPARATOR . $this->defaultInfectionConfigName;
        if (file_exists($projectConfigPath)) {
            $this->logger?->debug("Infection config found at: {configPath}", ['configPath' => $projectConfigPath]);
            return;
        }

        $this->logger?->info("Infection config not found at {configPath}. Attempting to create from template.", ['configPath' => $projectConfigPath]);

        // Path to the infection.json.dist within this tool's structure
        // Adjust this path according to your project's structure
        $distConfigPath = __DIR__ . '/../../config/' . $this->infectionJsonDistName;


        if (file_exists($distConfigPath)) {
            if (copy($distConfigPath, $projectConfigPath)) {
                $this->logger?->info("Copied infection.json.dist to {projectConfigPath}", ['projectConfigPath' => $projectConfigPath]);
            } else {
                $this->logger?->warning("Failed to copy infection.json.dist to {projectConfigPath}", ['projectConfigPath' => $projectConfigPath]);
            }
        } else {
            $this->logger?->warning("infection.json.dist template not found at {distConfigPath}. Infection might fail or use defaults.", ['distConfigPath' => $distConfigPath]);
            // Optionally, create a very basic default config programmatically
            $basicConfig = json_encode([
                "source" => ["directories" => ["src"]], // Adjust based on typical project structure
                "logs" => ["text" => "infection.log", "json" => "infection-report.json"],
                "phpUnit" => ["configDir" => "."]
            ], JSON_PRETTY_PRINT);
            if ($basicConfig) {
                FileHelper::writeFile($projectConfigPath, $basicConfig, $this->logger);
                $this->logger?->info("Created a basic default infection.json at {projectConfigPath}", ['projectConfigPath' => $projectConfigPath]);
            }
        }
    }

    /**
     * Parses MSI scores from Infection's text log or JSON report.
     * Prefers JSON report if available.
     */
    private function parseMsiFromLogs(string $textLogPath, string $jsonLogPath): ?array
    {
        // Try JSON report first (more reliable)
        if (file_exists($jsonLogPath)) {
            $jsonContent = FileHelper::readFile($jsonLogPath, $this->logger);
            if ($jsonContent) {
                $reportData = json_decode($jsonContent, true);
                if (isset($reportData['stats']['totalMsi'], $reportData['stats']['coveredCodeMsi'])) {
                    return [
                        'msi' => (float) $reportData['stats']['totalMsi'],
                        'covered_msi' => (float) $reportData['stats']['coveredCodeMsi'],
                    ];
                }
            }
        }

        // Fallback to parsing text log (less reliable)
        if (file_exists($textLogPath)) {
            $logContent = FileHelper::readFile($textLogPath, $this->logger);
            if ($logContent) {
                $msi = null;
                $coveredMsi = null;
                // Example regex, adjust based on actual Infection output format
                if (preg_match('/Mutation Score Indicator \(MSI\):\s*(\d+\.?\d*)%/', $logContent, $matches)) {
                    $msi = (float) $matches[1];
                }
                if (preg_match('/Covered Code MSI:\s*(\d+\.?\d*)%/', $logContent, $matches)) {
                    $coveredMsi = (float) $matches[1];
                }
                if ($msi !== null) {
                    return ['msi' => $msi, 'covered_msi' => $coveredMsi];
                }
            }
        }
        $this->logger?->warning("Could not parse MSI from any Infection log files.");
        return null;
    }
}
