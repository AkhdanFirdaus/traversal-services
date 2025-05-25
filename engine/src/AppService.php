<?php

declare(strict_types=1);

namespace App;

use App\Pipeline\RepositoryCloner;
use App\Pipeline\HeuristicAnalyzer;
use App\Pipeline\InfectionRunner;
use App\Pipeline\AiTestGenerator;
use App\Pipeline\TestSelector;
use App\Pipeline\Exporter;
use App\Utils\Logger;
use App\Utils\PatternLoader;
use App\Utils\FileHelper;
use App\Utils\SocketNotifier; // Tambahkan ini
use App\AST\VulnerabilityLocation; // Untuk type hint
use GuzzleHttp\Client as HttpClient;
use Symfony\Component\Process\Process;
use Throwable;
use DateTimeImmutable, DateTimeZone; // Untuk timestamp

class AppService
{
    private Logger $logger;
    private PatternLoader $patternLoader;
    private HttpClient $httpClient;
    private string $patternsJsonPath;
    private string $baseCloneDir;
    private string $baseExportDir;
    private string $reportsDir;
    private array $llmConfigs;
    private string $llmPreferenceOrder;
    private ?SocketNotifier $socketNotifier; // Tambahkan properti SocketNotifier

    public function __construct(
        Logger $logger,
        PatternLoader $patternLoader,
        HttpClient $httpClient,
        string $patternsJsonPath,
        string $baseCloneDir,
        string $baseExportDir,
        string $reportsDir,
        array $llmConfigs,
        string $llmPreferenceOrder,
        // Tambahkan parameter untuk konfigurasi SocketNotifier
        ?string $socketIoServerUrl,
        ?string $socketIoProgressEvent
    ) {
        $this->logger = $logger;
        $this->patternLoader = $patternLoader;
        $this->httpClient = $httpClient;
        $this->patternsJsonPath = $patternsJsonPath;
        $this->baseCloneDir = $baseCloneDir;
        $this->baseExportDir = $baseExportDir;
        $this->reportsDir = rtrim($reportsDir, DIRECTORY_SEPARATOR);
        $this->llmConfigs = $llmConfigs;
        $this->llmPreferenceOrder = $llmPreferenceOrder;

        // Inisialisasi SocketNotifier
        $this->socketNotifier = new SocketNotifier($socketIoServerUrl, $socketIoProgressEvent ?? 'pipeline_progress', $this->logger);
    }

    // Metode handleAnalyzeFile tetap sama, tapi bisa juga mengirim progres jika diinginkan
    public function handleAnalyzeFile(array $options): array
    {
        $filePath = $options['path'] ?? null;
        $taskId = $options['taskId'] ?? uniqid('task_'); // taskId untuk pelacakan

        $this->socketNotifier?->emitProgress('analyze_file_started', ['filePath' => $filePath], null, $taskId);

        if (!$filePath) {
            $this->socketNotifier?->emitProgress('analyze_file_failed', ['error' => "Missing 'path' parameter"], null, $taskId);
            throw new \InvalidArgumentException("Missing 'path' parameter for analyze-file.");
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->socketNotifier?->emitProgress('analyze_file_failed', ['error' => "File not found or not readable: {$filePath}"], null, $taskId);
            throw new \InvalidArgumentException("File not found or not readable: {$filePath}");
        }

        $analyzer = new HeuristicAnalyzer($this->logger, $this->patternLoader, $this->patternsJsonPath);
        $vulnerabilities = $analyzer->analyzeFile($filePath);
        $reportData = array_map(fn($v) => $v->toArray(), $vulnerabilities);

        $reportSaved = false;
        $reportPath = null;
        if (!empty($vulnerabilities)) {
            // ... (logika penyimpanan laporan sama) ...
            $reportFileName = 'heuristic_report_' . basename($filePath) . '_' . date('YmdHis') . '.json';
            $heuristicReportsSubDir = $this->reportsDir . '/heuristic_analysis';
            if(!is_dir($heuristicReportsSubDir)) mkdir($heuristicReportsSubDir, 0775, true);
            $reportPath = $heuristicReportsSubDir . DIRECTORY_SEPARATOR . $reportFileName;

            if (FileHelper::saveJsonReport($reportPath, $reportData, $this->logger)) {
                $reportSaved = true;
            }
        }
        
        $result = [
            'message' => empty($vulnerabilities) ? "No potential vulnerabilities found." : count($vulnerabilities) . " potential vulnerabilities found.",
            'filePath' => $filePath,
            'vulnerabilities' => $reportData,
            'reportPath' => $reportSaved ? $reportPath : null,
        ];
        $this->socketNotifier?->emitProgress('analyze_file_completed', $result, null, $taskId);
        return $result;
    }


    public function handleProcessRepo(array $options): array
    {
        $repoUrl = $options['url'] ?? null;
        $taskId = $options['taskId'] ?? uniqid('task_'); // taskId untuk pelacakan

        $this->socketNotifier?->emitProgress('process_repo_started', ['repoUrl' => $repoUrl], $repoUrl, $taskId);

        if (!$repoUrl) {
            $this->socketNotifier?->emitProgress('process_repo_failed', ['error' => "Missing 'url' parameter"], $repoUrl, $taskId);
            throw new \InvalidArgumentException("Missing 'url' parameter for process-repo.");
        }
        // ... (sisa inisialisasi $branch, $infectionOptions, $processLog, $msiReportData sama) ...
        $branch = $options['branch'] ?? null;
        $infectionOptsString = $options['infection-opts'] ?? '';
        $infectionBaseOptions = ['--log-verbosity=default'];
        if (strpos($infectionOptsString, '--logger-json') === false) {
            $infectionBaseOptions[] = '--logger-json=infection_report.json';
        }
        $userInfectionOptions = !empty($infectionOptsString) ? explode(' ', $infectionOptsString) : [];
        $infectionOptions = array_unique(array_merge($infectionBaseOptions, $userInfectionOptions));

        $this->logger->info("AppService: Starting full repository processing for URL: {repoUrl} (TaskID: {taskId})", ['repoUrl' => $repoUrl, 'taskId' => $taskId]);
        $processLog = [];
        $msiReportData = [
            'repositoryUrl' => $repoUrl,
            'processingTimestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
            'branch' => $branch,
            'taskId' => $taskId, // Sertakan taskId dalam laporan
            'initialMsiReport' => null,
            'finalMsiReportAfterLLM' => null,
            'msiImprovement' => null,
            'overallProcessLog' => &$processLog
        ];


        // 1. Clone Repository
        $this->socketNotifier?->emitProgress('cloning_repository', [], $repoUrl, $taskId);
        $cloner = new RepositoryCloner($this->logger, $this->baseCloneDir);
        $clonedRepoPath = $cloner->clone($repoUrl, $branch);
        if (!$clonedRepoPath) {
            $this->socketNotifier?->emitProgress('cloning_failed', ['error' => "Failed to clone repository"], $repoUrl, $taskId);
            // ... (logika error sama) ...
            $this->logger->error("AppService: Failed to clone repository: {repoUrl}. Aborting.", ['repoUrl' => $repoUrl]);
            throw new \RuntimeException("Failed to clone repository: {$repoUrl}.");
        }
        $processLog[] = "Repository cloned to: {$clonedRepoPath}";
        $this->socketNotifier?->emitProgress('cloning_completed', ['path' => $clonedRepoPath], $repoUrl, $taskId);

        // Composer Install
        $this->socketNotifier?->emitProgress('installing_dependencies', [], $repoUrl, $taskId);
        // ... (logika composer install sama) ...
        $processLog[] = "Attempting to install composer dependencies in cloned repo...";
        $composerInstallProcess = new Process(['composer', 'install', '--no-dev', '--no-interaction', '--optimize-autoloader', '--ignore-platform-reqs'], $clonedRepoPath);
        try {
            $composerInstallProcess->setTimeout(300)->mustRun();
            $processLog[] = "Composer dependencies installed successfully in cloned repo.";
            $this->socketNotifier?->emitProgress('dependencies_installed', [], $repoUrl, $taskId);
        } catch (Throwable $e) {
            $this->logger->warning("AppService: Composer install failed in cloned repo: {errorMessage}. Infection/tests might fail.", ['errorMessage' => $e->getMessage()]);
            $processLog[] = "Warning: Composer install failed in cloned repo: " . $e->getMessage();
            $this->socketNotifier?->emitProgress('dependencies_failed', ['error' => $e->getMessage()], $repoUrl, $taskId);
        }


        // 2. Heuristic Analysis
        $this->socketNotifier?->emitProgress('heuristic_analysis_started', [], $repoUrl, $taskId);
        // ... (logika analisis heuristik sama) ...
        $analyzer = new HeuristicAnalyzer($this->logger, $this->patternLoader, $this->patternsJsonPath);
        $allVulnerabilitiesFlat = [];
        $srcPath = $clonedRepoPath . DIRECTORY_SEPARATOR . (is_dir($clonedRepoPath . DIRECTORY_SEPARATOR . 'src') ? 'src' : '');
        if (!is_dir($srcPath) && is_dir($clonedRepoPath . DIRECTORY_SEPARATOR . 'app')) {
            $srcPath = $clonedRepoPath . DIRECTORY_SEPARATOR . 'app';
        } elseif (!is_dir($srcPath)) {
            $srcPath = $clonedRepoPath;
        }

        $vulnerabilitiesByFile = $analyzer->analyzeDirectory($srcPath);
        $heuristicReportPath = null;
        if (empty($vulnerabilitiesByFile)) {
            $processLog[] = "No potential vulnerabilities found via heuristic analysis.";
        } else {
            $processLog[] = "Heuristic analysis found potential vulnerabilities in " . count($vulnerabilitiesByFile) . " file(s).";
            foreach ($vulnerabilitiesByFile as $vulns) {
                $allVulnerabilitiesFlat = array_merge($allVulnerabilitiesFlat, $vulns);
            }
            $reportFileName = 'heuristic_report_repo_' . basename($repoUrl, '.git') . '_' . date('YmdHis') . '.json';
            $heuristicReportsSubDir = $this->reportsDir . '/heuristic_analysis';
            if(!is_dir($heuristicReportsSubDir)) mkdir($heuristicReportsSubDir, 0775, true);
            $heuristicReportPath = $heuristicReportsSubDir . DIRECTORY_SEPARATOR . $reportFileName;
            $reportData = array_map(fn($v) => $v->toArray(), $allVulnerabilitiesFlat);
            FileHelper::saveJsonReport($heuristicReportPath, $reportData, $this->logger);
            $processLog[] = "Combined heuristic report saved to: {$heuristicReportPath}";
        }
         $msiReportData['heuristicAnalysisReportPath'] = $heuristicReportPath;
         $msiReportData['vulnerabilitiesFound'] = count($allVulnerabilitiesFlat);
        $this->socketNotifier?->emitProgress('heuristic_analysis_completed', ['vulnerabilitiesFound' => count($allVulnerabilitiesFlat), 'reportPath' => $heuristicReportPath], $repoUrl, $taskId);


        // 3. Run Infection (Initial)
        $this->socketNotifier?->emitProgress('initial_infection_started', [], $repoUrl, $taskId);
        // ... (logika Infection awal sama, pastikan opsi unik untuk log) ...
        $infectionRunner = new InfectionRunner($this->logger);
        $processLog[] = "Running initial Infection scan...";
        $initialInfectionOptions = $infectionOptions;
        $initialInfectionOptions[] = '--logger-json=initial_infection_report.json';
        $initialInfectionOptions[] = '--log-file=initial_infection.log';

        $initialInfectionResults = $infectionRunner->run($clonedRepoPath, null, $initialInfectionOptions);
        $initialMsi = null;
        if ($initialInfectionResults) {
            $initialMsi = $initialInfectionResults['msi'];
            $msiReportData['initialMsiReport'] = [
                'msi' => $initialMsi,
                'coveredMsi' => $initialInfectionResults['covered_msi'],
                'infectionLogPath' => $initialInfectionResults['text_log_path'],
                'infectionJsonReportPath' => $initialInfectionResults['json_report_path'],
                'details' => "MSI score before any AI-generated tests were added."
            ];
            if ($initialMsi !== null) {
                 $processLog[] = "Initial MSI: {$initialMsi}%";
            } else {
                 $processLog[] = "Could not determine initial MSI (parsed as null).";
            }
             $this->socketNotifier?->emitProgress('initial_infection_completed', $msiReportData['initialMsiReport'], $repoUrl, $taskId);
        } else {
            $processLog[] = "Initial Infection run failed or produced no parsable results.";
             $msiReportData['initialMsiReport'] = ['error' => 'Infection run failed or no results.'];
             $this->socketNotifier?->emitProgress('initial_infection_failed', ['error' => 'Infection run failed or no results.'], $repoUrl, $taskId);
        }

        // 4. AI Test Generation
        $generatedTestsData = [];
        $aiTestsGeneratedCount = 0;
        if (!empty($allVulnerabilitiesFlat) && !empty($this->llmConfigs)) {
            $this->socketNotifier?->emitProgress('ai_test_generation_started', ['vulnerabilityCount' => count($allVulnerabilitiesFlat)], $repoUrl, $taskId);
            $aiGenerator = new AiTestGenerator($this->logger, $this->httpClient, $this->llmConfigs, $this->llmPreferenceOrder);
            // ... (logika iterasi $allVulnerabilitiesFlat dan pemanggilan $aiGenerator->generateTestsForVulnerability sama) ...
            foreach ($allVulnerabilitiesFlat as $idx => $vuln) {
                $this->logger->info("AppService: Requesting AI test for vulnerability #{$idx} in {$vuln->filePath}");
                // Kirim update per kerentanan yang diproses AI
                $this->socketNotifier?->emitProgress('ai_processing_vulnerability', [
                    'vulnerabilityIndex' => $idx + 1,
                    'totalVulnerabilities' => count($allVulnerabilitiesFlat),
                    'filePath' => $vuln->filePath,
                    'line' => $vuln->startLine
                ], $repoUrl, $taskId);

                $generatedTestCode = $aiGenerator->generateTestsForVulnerability($vuln);
                if ($generatedTestCode) {
                    $aiTestsGeneratedCount++;
                    $testFileNameHint = "AiGenerated_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $vuln->cweId) . "_" . basename($vuln->filePath, '.php') . "_" . uniqid() ."Test.php";
                    $currentGeneratedTest = [
                        'code' => $generatedTestCode,
                        'source_vulnerability_cwe' => $vuln->cweId,
                        'source_vulnerability_file' => $vuln->filePath,
                        'filenameHint' => $testFileNameHint,
                        // Sertakan snippet kode asli untuk perbandingan
                        'originalCodeSnippet' => $vuln->codeSnippet
                    ];
                    $generatedTestsData[] = $currentGeneratedTest;
                    // Kirim update dengan kode yang dihasilkan
                    $this->socketNotifier?->emitProgress('ai_test_generated', [
                        'vulnerabilityIndex' => $idx + 1,
                        'filePath' => $vuln->filePath,
                        'originalCode' => $vuln->codeSnippet, // Kode sumber sebelum (snippet kerentanan)
                        'generatedTest' => $generatedTestCode  // Kode sumber sesudah (test case baru)
                    ], $repoUrl, $taskId);
                } else {
                     $this->socketNotifier?->emitProgress('ai_test_generation_failed_for_vuln', [
                        'vulnerabilityIndex' => $idx + 1,
                        'filePath' => $vuln->filePath,
                    ], $repoUrl, $taskId);
                }
            }
            $processLog[] = "AI generated {$aiTestsGeneratedCount} test(s).";
            $this->socketNotifier?->emitProgress('ai_test_generation_completed', ['generatedCount' => $aiTestsGeneratedCount], $repoUrl, $taskId);
        } else {
            // ... (logika jika LLM tidak dikonfigurasi atau tidak ada kerentanan) ...
            $processLog[] = "Skipping AI test generation (no vulnerabilities or LLM not configured).";
            $this->socketNotifier?->emitProgress('ai_test_generation_skipped', ['reason' => 'No vulnerabilities or LLM not configured.'], $repoUrl, $taskId);
        }
        $msiReportData['aiTestsGeneratedCount'] = $aiTestsGeneratedCount;


        // 5. Add AI tests to project and Run Infection (Final)
        $finalMsi = null;
        if (!empty($generatedTestsData)) {
            $this->socketNotifier?->emitProgress('final_infection_started', ['aiTestCount' => $aiTestsGeneratedCount], $repoUrl, $taskId);
            // ... (logika integrasi tes AI dan Infection akhir sama, pastikan opsi unik untuk log) ...
            $processLog[] = "Attempting to integrate AI-generated tests and run final Infection scan...";
            $aiTestFilesWritten = 0;
            $aiTestsDir = $clonedRepoPath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'AiGenerated';
            if (!is_dir($aiTestsDir)) mkdir($aiTestsDir, 0775, true);

            foreach($generatedTestsData as $testData) {
                $testFilePath = $aiTestsDir . DIRECTORY_SEPARATOR . $testData['filenameHint'];
                if (FileHelper::writeFile($testFilePath, $testData['code'], $this->logger)) {
                    $aiTestFilesWritten++;
                } else {
                    $this->logger->error("Failed to write AI test file: {$testFilePath}");
                }
            }
            $processLog[] = "{$aiTestFilesWritten} AI-generated test files written to {$aiTestsDir}.";

            if ($aiTestFilesWritten > 0) {
                $this->logger->info("Running composer dump-autoload in cloned repo after adding AI tests.");
                $composerDumpProcess = new Process(['composer', 'dump-autoload', '--optimize'], $clonedRepoPath);
                try {
                    $composerDumpProcess->mustRun();
                    $processLog[] = "Composer dump-autoload completed in cloned repo.";
                } catch (Throwable $e) {
                    $this->logger->warning("Composer dump-autoload failed: {$e->getMessage()}");
                    $processLog[] = "Warning: Composer dump-autoload failed after adding AI tests.";
                }

                $finalInfectionOptions = $infectionOptions;
                $finalInfectionOptions[] = '--logger-json=final_infection_report.json';
                $finalInfectionOptions[] = '--log-file=final_infection.log';

                $finalInfectionResults = $infectionRunner->run($clonedRepoPath, null, $finalInfectionOptions);
                if ($finalInfectionResults) {
                    $finalMsi = $finalInfectionResults['msi'];
                    $msiReportData['finalMsiReportAfterLLM'] = [
                        'msi' => $finalMsi,
                        'coveredMsi' => $finalInfectionResults['covered_msi'],
                        'infectionLogPath' => $finalInfectionResults['text_log_path'],
                        'infectionJsonReportPath' => $finalInfectionResults['json_report_path'],
                        'aiTestsAppliedCount' => $aiTestFilesWritten,
                        'details' => "MSI score after AI-generated tests were integrated and executed."
                    ];
                     if ($finalMsi !== null) {
                        $processLog[] = "Final MSI after AI tests: {$finalMsi}%";
                        if ($initialMsi !== null) {
                            $msiReportData['msiImprovement'] = round($finalMsi - $initialMsi, 2);
                            $processLog[] = "MSI Improvement: {$msiReportData['msiImprovement']}%";
                        }
                    } else {
                        $processLog[] = "Could not determine final MSI (parsed as null).";
                    }
                    $this->socketNotifier?->emitProgress('final_infection_completed', $msiReportData['finalMsiReportAfterLLM'], $repoUrl, $taskId);
                } else {
                    $processLog[] = "Final Infection run failed or produced no results after adding AI tests.";
                    $msiReportData['finalMsiReportAfterLLM'] = ['error' => 'Final Infection run failed or no results.'];
                    $this->socketNotifier?->emitProgress('final_infection_failed', ['error' => 'Infection run failed or no results.'], $repoUrl, $taskId);
                }
            } else {
                 $processLog[] = "No AI tests were successfully written, skipping final Infection run.";
                 $msiReportData['finalMsiReportAfterLLM'] = ['details' => 'No AI tests were written, so final Infection run was skipped.'];
                 $this->socketNotifier?->emitProgress('final_infection_skipped', ['reason' => 'No AI tests written.'], $repoUrl, $taskId);
            }
        } else {
            // ... (logika jika tidak ada tes AI yang dihasilkan) ...
            $processLog[] = "No AI tests generated, skipping final Infection run.";
            $this->socketNotifier?->emitProgress('final_infection_skipped', ['reason' => 'No AI tests generated.'], $repoUrl, $taskId);
            $msiReportData['finalMsiReportAfterLLM'] = ['details' => 'No AI tests were generated.'];
        }


        // 6. Select Best Tests
        // ... (logika seleksi sama) ...
        $testSelector = new TestSelector($this->logger);
        $bestTests = $testSelector->selectBestTests($generatedTestsData);
        $processLog[] = count($bestTests) . " AI-generated tests selected based on initial criteria.";
        $msiReportData['aiTestsSelectedCount'] = count($bestTests);
        $this->socketNotifier?->emitProgress('test_selection_completed', ['selectedCount' => count($bestTests)], $repoUrl, $taskId);

        // 7. Export Test Cases
        // ... (logika ekspor sama) ...
        $exportedZipPath = null;
        if (!empty($bestTests)) {
            $exporter = new Exporter($this->logger, $this->baseExportDir);
            $exportName = basename($repoUrl, '.git') . '_ai_tests_' . date('YmdHis');
            $exportedZipPath = $exporter->exportTests($bestTests, $exportName, 'zip');
            if ($exportedZipPath) {
                $processLog[] = "Selected AI tests exported to: {$exportedZipPath}";
                $this->socketNotifier?->emitProgress('tests_exported', ['path' => $exportedZipPath], $repoUrl, $taskId);
            } else {
                $processLog[] = "Failed to export selected AI tests.";
                $this->socketNotifier?->emitProgress('export_failed', [], $repoUrl, $taskId);
            }
        }
        $msiReportData['exportedAiTestsPath'] = $exportedZipPath;

        // Simpan Laporan MSI Gabungan
        // ... (logika penyimpanan laporan MSI sama) ...
        $msiReportSubDir = $this->reportsDir . '/msi_reports';
        if (!is_dir($msiReportSubDir)) mkdir($msiReportSubDir, 0775, true);
        $msiReportFilename = 'msi_report_' . basename($repoUrl, '.git') . '_' . date('YmdHis') . '.json';
        $msiReportFullPath = $msiReportSubDir . DIRECTORY_SEPARATOR . $msiReportFilename;
        FileHelper::saveJsonReport($msiReportFullPath, $msiReportData, $this->logger);
        $this->logger->info("MSI comparison report saved to: {msiReportPath}", ['msiReportPath' => $msiReportFullPath]);


        // 8. Cleanup
        // ... (logika cleanup sama) ...
        $clonedRepoCleanupMessage = "Cloned repository cleanup action for {$clonedRepoPath}.";
        if ($clonedRepoPath && is_dir($clonedRepoPath)) {
            if ($cloner->cleanup($clonedRepoPath)) {
                $clonedRepoCleanupMessage .= " Success.";
            } else {
                $clonedRepoCleanupMessage .= " Failed or partially failed.";
            }
        } else {
            $clonedRepoCleanupMessage = "No valid cloned repository path found at {$clonedRepoPath} for cleanup.";
        }
        $processLog[] = $clonedRepoCleanupMessage;
        $this->logger->info($clonedRepoCleanupMessage);

        $processLog[] = "Repository processing finished for: {$repoUrl}";
        $this->socketNotifier?->emitProgress('process_repo_completed', ['finalReportPath' => $msiReportFullPath], $repoUrl, $taskId);
        $this->socketNotifier?->close(); // Tutup koneksi Socket.IO di akhir proses

        return [
            'message' => "Repository processing completed for {$repoUrl}.",
            'repoUrl' => $repoUrl,
            'taskId' => $taskId, // Kembalikan taskId
            'heuristicAnalysisReportPath' => $heuristicReportPath,
            'vulnerabilitiesFound' => count($allVulnerabilitiesFlat),
            'initialMsi' => $initialMsi,
            'aiTestsGeneratedCount' => $aiTestsGeneratedCount,
            'aiTestsSelectedCount' => count($bestTests),
            'finalMsi' => $finalMsi,
            'msiImprovement' => $msiReportData['msiImprovement'],
            'exportedAiTestsPath' => $exportedZipPath,
            'msiComparisonReportPath' => $msiReportFullPath,
            'processLog' => $processLog
        ];
    }

    // Pastikan __destruct juga menutup koneksi SocketNotifier jika ada
    public function __destruct()
    {
        $this->socketNotifier?->close();
    }
}
