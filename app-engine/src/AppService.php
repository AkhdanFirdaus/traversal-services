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
    private string $reportsDir; // Direktori utama untuk semua jenis laporan
    private string $llmApiKey;
    private string $llmModelName;

    public function __construct(
        Logger $logger,
        PatternLoader $patternLoader,
        HttpClient $httpClient,
        string $patternsJsonPath,
        string $baseCloneDir,
        string $baseExportDir,
        string $reportsDir, // Terima direktori laporan utama
        string $llmApiKey,
        string $llmModelName
    ) {
        $this->logger = $logger;
        $this->patternLoader = $patternLoader;
        $this->httpClient = $httpClient;
        $this->patternsJsonPath = $patternsJsonPath;
        $this->baseCloneDir = $baseCloneDir;
        $this->baseExportDir = $baseExportDir;
        $this->reportsDir = rtrim($reportsDir, DIRECTORY_SEPARATOR); // Pastikan tidak ada trailing slash
        $this->llmApiKey = $llmApiKey;
        $this->llmModelName = $llmModelName;
    }

    // Metode handleAnalyzeFile tetap sama seperti sebelumnya...
    /**
     * Handles the 'analyze-file' action.
     * @param array $options Options, expected to contain 'path'.
     * @return array Result array with 'message', 'filePath', 'vulnerabilities'.
     * @throws \InvalidArgumentException If path is missing or invalid.
     */
    public function handleAnalyzeFile(array $options): array
    {
        $filePath = $options['path'] ?? null;
        if (!$filePath) {
            throw new \InvalidArgumentException("Missing 'path' parameter for analyze-file.");
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File not found or not readable: {$filePath}");
        }

        $analyzer = new HeuristicAnalyzer($this->logger, $this->patternLoader, $this->patternsJsonPath);
        $vulnerabilities = $analyzer->analyzeFile($filePath);
        $reportData = array_map(fn($v) => $v->toArray(), $vulnerabilities);

        $reportSaved = false;
        $reportPath = null;
        if (!empty($vulnerabilities)) {
            $reportFileName = 'heuristic_report_' . basename($filePath) . '_' . date('YmdHis') . '.json';
            // Pastikan subdirektori ada
            $heuristicReportsSubDir = $this->reportsDir . '/heuristic_analysis';
            if(!is_dir($heuristicReportsSubDir)) mkdir($heuristicReportsSubDir, 0775, true);
            $reportPath = $heuristicReportsSubDir . DIRECTORY_SEPARATOR . $reportFileName;

            if (FileHelper::saveJsonReport($reportPath, $reportData, $this->logger)) {
                $reportSaved = true;
            }
        }

        return [
            'message' => empty($vulnerabilities) ? "No potential vulnerabilities found." : count($vulnerabilities) . " potential vulnerabilities found.",
            'filePath' => $filePath,
            'vulnerabilities' => $reportData,
            'reportPath' => $reportSaved ? $reportPath : null,
        ];
    }


    public function handleProcessRepo(array $options): array
    {
        $repoUrl = $options['url'] ?? null;
        if (!$repoUrl) {
            throw new \InvalidArgumentException("Missing 'url' parameter for process-repo.");
        }
        $branch = $options['branch'] ?? null;
        $infectionOptsString = $options['infection-opts'] ?? '';
        // Tambahkan --logger-json ke opsi default jika belum ada, untuk parsing yang lebih baik
        $infectionBaseOptions = ['--log-verbosity=default'];
        if (strpos($infectionOptsString, '--logger-json') === false) {
            $infectionBaseOptions[] = '--logger-json=infection_report.json';
        }
        $userInfectionOptions = !empty($infectionOptsString) ? explode(' ', $infectionOptsString) : [];
        $infectionOptions = array_unique(array_merge($infectionBaseOptions, $userInfectionOptions));


        $this->logger->info("AppService: Starting full repository processing for URL: {repoUrl}", ['repoUrl' => $repoUrl]);
        $processLog = [];
        $msiReportData = [
            'repositoryUrl' => $repoUrl,
            'processingTimestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
            'branch' => $branch,
            'initialMsiReport' => null,
            'finalMsiReportAfterLLM' => null,
            'msiImprovement' => null,
            'overallProcessLog' => &$processLog // Referensi ke $processLog
        ];

        // 1. Clone Repository
        $cloner = new RepositoryCloner($this->logger, $this->baseCloneDir);
        $clonedRepoPath = $cloner->clone($repoUrl, $branch);
        if (!$clonedRepoPath) {
            $this->logger->error("AppService: Failed to clone repository: {repoUrl}. Aborting.", ['repoUrl' => $repoUrl]);
            throw new \RuntimeException("Failed to clone repository: {$repoUrl}.");
        }
        $processLog[] = "Repository cloned to: {$clonedRepoPath}";

        // Composer Install
        $processLog[] = "Attempting to install composer dependencies in cloned repo...";
        $composerInstallProcess = new Process(['composer', 'install', '--no-dev', '--no-interaction', '--optimize-autoloader', '--ignore-platform-reqs'], $clonedRepoPath);
        try {
            $composerInstallProcess->setTimeout(300)->mustRun();
            $processLog[] = "Composer dependencies installed successfully in cloned repo.";
        } catch (Throwable $e) {
            $this->logger->warning("AppService: Composer install failed in cloned repo: {errorMessage}. Infection/tests might fail.", ['errorMessage' => $e->getMessage()]);
            $processLog[] = "Warning: Composer install failed in cloned repo: " . $e->getMessage();
        }

        // 2. Heuristic Analysis
        $analyzer = new HeuristicAnalyzer($this->logger, $this->patternLoader, $this->patternsJsonPath);
        $allVulnerabilitiesFlat = [];
        $srcPath = $clonedRepoPath . DIRECTORY_SEPARATOR . (is_dir($clonedRepoPath . DIRECTORY_SEPARATOR . 'src') ? 'src' : '');
        if (!is_dir($srcPath) && is_dir($clonedRepoPath . DIRECTORY_SEPARATOR . 'app')) { // Check for 'app' dir as common alternative
            $srcPath = $clonedRepoPath . DIRECTORY_SEPARATOR . 'app';
        } elseif (!is_dir($srcPath)) {
            $srcPath = $clonedRepoPath; // Fallback to root
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

        // 3. Run Infection (Initial)
        $infectionRunner = new InfectionRunner($this->logger);
        $processLog[] = "Running initial Infection scan...";
        // Pastikan nama file log unik untuk run ini
        $initialInfectionOptions = $infectionOptions;
        $initialInfectionOptions[] = '--logger-json=initial_infection_report.json'; // Nama file JSON log unik
        $initialInfectionOptions[] = '--log-file=initial_infection.log'; // Nama file text log unik


        $initialInfectionResults = $infectionRunner->run($clonedRepoPath, null, $initialInfectionOptions);
        $initialMsi = null;
        if ($initialInfectionResults) {
            $initialMsi = $initialInfectionResults['msi'];
            $msiReportData['initialMsiReport'] = [
                'msi' => $initialMsi,
                'coveredMsi' => $initialInfectionResults['covered_msi'],
                'infectionLogPath' => $initialInfectionResults['text_log_path'], // Seharusnya path absolut atau relatif dari $clonedRepoPath
                'infectionJsonReportPath' => $initialInfectionResults['json_report_path'],
                'details' => "MSI score before any AI-generated tests were added."
            ];
            if ($initialMsi !== null) {
                 $processLog[] = "Initial MSI: {$initialMsi}%";
            } else {
                 $processLog[] = "Could not determine initial MSI (parsed as null).";
            }
        } else {
            $processLog[] = "Initial Infection run failed or produced no parsable results.";
             $msiReportData['initialMsiReport'] = ['error' => 'Infection run failed or no results.'];
        }

        // 4. AI Test Generation
        $generatedTestsData = [];
        $aiTestsGeneratedCount = 0;
        if (!empty($allVulnerabilitiesFlat) && !empty($this->llmApiKey)) {
            $aiGenerator = new AiTestGenerator($this->logger, $this->httpClient, $this->llmApiKey, $this->llmModelName);
            $processLog[] = "Generating AI tests for " . count($allVulnerabilitiesFlat) . " found vulnerabilities...";
            foreach ($allVulnerabilitiesFlat as $idx => $vuln) {
                $this->logger->info("AppService: Requesting AI test for vulnerability #{$idx} in {$vuln->filePath}");
                $generatedTestCode = $aiGenerator->generateTestsForVulnerability($vuln);
                if ($generatedTestCode) {
                    $aiTestsGeneratedCount++;
                    $testFileNameHint = "AiGenerated_" . preg_replace('/[^a-zA-Z0-9_-]/', '_', $vuln->cweId) . "_" . basename($vuln->filePath, '.php') . "_" . uniqid() ."Test.php";
                    $generatedTestsData[] = [
                        'code' => $generatedTestCode,
                        'source_vulnerability_cwe' => $vuln->cweId,
                        'source_vulnerability_file' => $vuln->filePath,
                        'filenameHint' => $testFileNameHint
                    ];
                }
            }
            $processLog[] = "AI generated {$aiTestsGeneratedCount} test(s).";
        } elseif (empty($this->llmApiKey)) {
            $this->logger->warning("AppService: LLM_API_KEY not set. Skipping AI test generation.");
            $processLog[] = "LLM_API_KEY not set. Skipping AI test generation.";
        }
         $msiReportData['aiTestsGeneratedCount'] = $aiTestsGeneratedCount;

        // 5. Add AI tests to project and Run Infection (Final)
        $finalMsi = null;
        if (!empty($generatedTestsData)) {
            $processLog[] = "Attempting to integrate AI-generated tests and run final Infection scan...";
            // **LANGKAH KRUSIAL (Membutuhkan Implementasi Rinci):**
            // Di sini Anda perlu logika untuk:
            // a. Menentukan path yang tepat untuk menyimpan file test AI di dalam $clonedRepoPath/tests/
            //    (misalnya, $clonedRepoPath/tests/AiGenerated/). Buat direktorinya jika belum ada.
            // b. Menyimpan setiap $testData['code'] ke file yang sesuai.
            // c. Memastikan PHPUnit/test runner proyek yang di-clone akan menemukan tes-tes baru ini.
            //    Ini mungkin melibatkan pembaruan `phpunit.xml` atau mengikuti konvensi penamaan.
            // d. Untuk saat ini, kita hanya log bahwa tes "akan ditambahkan".
            $testIntegrationSuccess = true; // Asumsikan sukses untuk demo
            $aiTestFilesWritten = 0;
            $aiTestsDir = $clonedRepoPath . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'AiGenerated';
            if (!is_dir($aiTestsDir)) mkdir($aiTestsDir, 0775, true);

            foreach($generatedTestsData as $testData) {
                $testFilePath = $aiTestsDir . DIRECTORY_SEPARATOR . $testData['filenameHint'];
                if (FileHelper::writeFile($testFilePath, $testData['code'], $this->logger)) {
                    $aiTestFilesWritten++;
                } else {
                    $this->logger->error("Failed to write AI test file: {$testFilePath}");
                    // $testIntegrationSuccess = false; // Bisa jadi error parsial
                }
            }
            $processLog[] = "{$aiTestFilesWritten} AI-generated test files written to {$aiTestsDir}.";


            if ($aiTestFilesWritten > 0) {
                // Jalankan 'composer dump-autoload' di cloned repo jika ada tes baru di namespace baru
                // (Tergantung bagaimana Anda mengatur namespace tes AI)
                $this->logger->info("Running composer dump-autoload in cloned repo after adding AI tests.");
                $composerDumpProcess = new Process(['composer', 'dump-autoload', '--optimize'], $clonedRepoPath);
                try {
                    $composerDumpProcess->mustRun();
                    $processLog[] = "Composer dump-autoload completed in cloned repo.";
                } catch (Throwable $e) {
                    $this->logger->warning("Composer dump-autoload failed: {$e->getMessage()}");
                    $processLog[] = "Warning: Composer dump-autoload failed after adding AI tests.";
                }


                // Pastikan nama file log unik untuk run ini
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
                        'aiTestsAppliedCount' => $aiTestFilesWritten, // atau count($generatedTestsData)
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
                } else {
                    $processLog[] = "Final Infection run failed or produced no results after adding AI tests.";
                    $msiReportData['finalMsiReportAfterLLM'] = ['error' => 'Final Infection run failed or no results.'];
                }
            } else {
                 $processLog[] = "No AI tests were successfully written, skipping final Infection run.";
                 $msiReportData['finalMsiReportAfterLLM'] = ['details' => 'No AI tests were written, so final Infection run was skipped.'];
            }
        } else {
            $processLog[] = "No AI tests generated, skipping final Infection run.";
            $msiReportData['finalMsiReportAfterLLM'] = ['details' => 'No AI tests were generated.'];
        }


        // 6. Select Best Tests (berdasarkan data yang ada)
        $testSelector = new TestSelector($this->logger);
        // Perlu data metrik dari hasil infeksi kedua untuk seleksi yang lebih baik
        $bestTests = $testSelector->selectBestTests($generatedTestsData); // $generatedTestsData mungkin perlu diperbarui dengan hasil infeksi
        $processLog[] = count($bestTests) . " AI-generated tests selected based on initial criteria.";
        $msiReportData['aiTestsSelectedCount'] = count($bestTests);

        // 7. Export Test Cases
        $exportedZipPath = null;
        if (!empty($bestTests)) {
            $exporter = new Exporter($this->logger, $this->baseExportDir);
            $exportName = basename($repoUrl, '.git') . '_ai_tests_' . date('YmdHis');
            $exportedZipPath = $exporter->exportTests($bestTests, $exportName, 'zip');
            if ($exportedZipPath) {
                $processLog[] = "Selected AI tests exported to: {$exportedZipPath}";
            } else {
                $processLog[] = "Failed to export selected AI tests.";
            }
        }
        $msiReportData['exportedAiTestsPath'] = $exportedZipPath;

        // Simpan Laporan MSI Gabungan
        $msiReportSubDir = $this->reportsDir . '/msi_reports';
        if (!is_dir($msiReportSubDir)) mkdir($msiReportSubDir, 0775, true);
        $msiReportFilename = 'msi_report_' . basename($repoUrl, '.git') . '_' . date('YmdHis') . '.json';
        $msiReportFullPath = $msiReportSubDir . DIRECTORY_SEPARATOR . $msiReportFilename;
        FileHelper::saveJsonReport($msiReportFullPath, $msiReportData, $this->logger);
        $this->logger->info("MSI comparison report saved to: {msiReportPath}", ['msiReportPath' => $msiReportFullPath]);


        // 8. Cleanup (selalu di akhir blok try utama)
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

        // Data yang dikembalikan oleh AppService
        return [
            'message' => "Repository processing completed for {$repoUrl}.",
            'repoUrl' => $repoUrl,
            // 'clonedPath' => $clonedRepoPath, // Mungkin tidak relevan karena sudah di-cleanup
            'heuristicAnalysisReportPath' => $heuristicReportPath,
            'vulnerabilitiesFound' => count($allVulnerabilitiesFlat),
            'initialMsi' => $initialMsi,
            'aiTestsGeneratedCount' => $aiTestsGeneratedCount,
            'aiTestsSelectedCount' => count($bestTests),
            'finalMsi' => $finalMsi,
            'msiImprovement' => $msiReportData['msiImprovement'],
            'exportedAiTestsPath' => $exportedZipPath,
            'msiComparisonReportPath' => $msiReportFullPath, // Path ke laporan MSI gabungan
            'processLog' => $processLog // Log langkah-langkah untuk output CLI/API
        ];
    }
}
