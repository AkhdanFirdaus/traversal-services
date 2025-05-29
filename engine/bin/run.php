#!/usr/bin/env php
<?php

// bin/run.php (CLI Only)

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\AppService;
use App\Utils\Logger;
use App\Utils\PatternLoader;
// use App\Utils\FileHelper; // Tidak secara langsung digunakan di sini lagi
use GuzzleHttp\Client as HttpClient;
use Dotenv\Dotenv;
use Psr\Log\LogLevel;

// --- Muat Variabel Environment ---
$isDockerManagedEnv = (getenv('APP_ENV_LOADER') === 'docker-compose');

if (!$isDockerManagedEnv) {
    // --- Muat Variabel Environment ---
    try {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
    } catch (\Dotenv\Exception\InvalidPathException $e) {
        echo "Warning: .env file not found. Relying on system environment variables or defaults.\n";
    }
} else {
    // --- Docker Compose Environment ---
    if (!isset($_ENV['APP_ENV_LOADER']) || $_ENV['APP_ENV_LOADER'] !== 'docker-compose') {
        echo "Error: This script must be run in a Docker-managed environment.\n";
        exit(1);
    }
    // Pastikan variabel lingkungan sudah di-set oleh Docker Compose
    if (!isset($_ENV['LOG_PATH']) || !isset($_ENV['LOG_LEVEL'])) {
        echo "Error: Required environment variables are not set in the Docker environment.\n";
        exit(1);
    }
}

// --- Konfigurasi dari Environment Variables ---
$logFilePath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../tmp/app_cli.log';
$logLevelName = strtoupper($_ENV['LOG_LEVEL'] ?? 'INFO');
$logLevel = match ($logLevelName) {
    'DEBUG' => Logger::DEBUG, 'WARNING' => Logger::WARNING, 'ERROR' => Logger::ERROR, default => Logger::INFO,
};
$patternsJsonPath = $_ENV['PATTERNS_JSON_PATH'] ?? __DIR__ . '/../config/patterns.json';
$baseCloneDir = $_ENV['BASE_CLONE_DIR_CLI'] ?? $_ENV['BASE_CLONE_DIR'] ?? __DIR__ . '/../tmp/clones_cli';
$baseExportDir = $_ENV['BASE_EXPORT_DIR_CLI'] ?? $_ENV['BASE_EXPORT_DIR'] ?? __DIR__ . '/../reports/exported_test_cases_cli';
$reportsDir = $_ENV['REPORTS_DIR_CLI'] ?? $_ENV['REPORTS_DIR'] ?? __DIR__ . '/../reports';

// Konfigurasi LLM
$llmPreferenceOrder = $_ENV['LLM_PREFERENCE_ORDER'] ?? 'gemini,openai,anthropic';
$llmConfigs = [
    'gemini' => ['api_key' => $_ENV['GEMINI_API_KEY'] ?? null, 'model_name' => $_ENV['GEMINI_MODEL_NAME'] ?? 'gemini-1.5-flash-latest'],
    'openai' => ['api_key' => $_ENV['OPENAI_API_KEY'] ?? null, 'model_name' => $_ENV['OPENAI_MODEL_NAME'] ?? 'gpt-3.5-turbo'],
    'anthropic' => ['api_key' => $_ENV['ANTHROPIC_API_KEY'] ?? null, 'model_name' => $_ENV['ANTHROPIC_MODEL_NAME'] ?? 'claude-3-haiku-20240307', 'api_version' => $_ENV['ANTHROPIC_API_VERSION'] ?? '2023-06-01'],
];

// Konfigurasi Socket.IO
$socketIoServerUrl = $_ENV['SOCKET_IO_SERVER_URL'] ?? null;
$socketIoProgressEvent = $_ENV['SOCKET_IO_PROGRESS_EVENT'] ?? 'pipeline_progress';


// --- Inisialisasi Komponen ---
$logLevelName = strtoupper($_ENV['LOG_LEVEL'] ?? 'INFO'); // INFO adalah default yang baik
// Pastikan $logLevelName adalah salah satu konstanta dari LogLevel
// Jika tidak, default ke LogLevel::INFO
$validLogLevels = [
    'DEBUG' => LogLevel::DEBUG, 'INFO' => LogLevel::INFO, 'NOTICE' => LogLevel::NOTICE,
    'WARNING' => LogLevel::WARNING, 'ERROR' => LogLevel::ERROR, 'CRITICAL' => LogLevel::CRITICAL,
    'ALERT' => LogLevel::ALERT, 'EMERGENCY' => LogLevel::EMERGENCY
];
$psrLogLevel = $validLogLevels[$logLevelName] ?? LogLevel::INFO;

$logger = new Logger($logFilePath, $psrLogLevel);
$patternLoader = new PatternLoader($logger);
$httpClient = new HttpClient(['timeout' => (float)($_ENV['HTTP_CLIENT_TIMEOUT'] ?? 60.0)]);

$appService = new AppService(
    $logger, $patternLoader, $httpClient,
    $patternsJsonPath, $baseCloneDir, $baseExportDir, $reportsDir,
    $llmConfigs, $llmPreferenceOrder,
    $socketIoServerUrl, $socketIoProgressEvent // Teruskan konfigurasi Socket.IO
);

// --- Parsing Argumen CLI ---
$action = $argv[1] ?? null;
$options = [];
for ($i = 2; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--')) {
        $parts = explode('=', substr($argv[$i], 2), 2);
        $options[$parts[0]] = $parts[1] ?? true;
    }
}
// Tambahkan taskId jika belum ada dari opsi, untuk konsistensi
if (!isset($options['taskId'])) {
    $options['taskId'] = 'cli_task_' . uniqid();
}


$logger->info("CLI Application started. Action: {action}. TaskID: {taskId}. Options: {options_json}", [
    'action' => $action ?? 'none', 'taskId' => $options['taskId'], 'options_json' => json_encode($options)
]);

// --- Fungsi Bantuan CLI (cliShowHelp tetap sama) ---
function cliShowHelp(string $errorMessage = ''): void
{
    if ($errorMessage) echo "Error: {$errorMessage}\n\n";
    echo "PHP Test Case Generator CLI\n";
    echo "Usage: php bin/run.php <command> [options]\n\n";
    echo "Commands:\n";
    echo "  analyze-file --path=<file_path.php>   Analyzes a single PHP file.\n";
    echo "                                        [--taskId=<custom_task_id>]\n";
    echo "  process-repo --url=<git_repo_url>     Processes a full repository.\n";
    echo "                                        [--branch=<branch_name>]\n";
    echo "                                        [--infection-opts=\"--min-msi=50 --threads=2\"]\n";
    echo "                                        [--taskId=<custom_task_id>]\n";
    echo "\n";
    exit($errorMessage ? 1 : 0);
}


// --- Dispatcher Perintah CLI (logika output tetap sama, AppService yang mengirim update socket) ---
try {
    switch ($action) {
        case 'analyze-file':
            $result = $appService->handleAnalyzeFile($options);
            // ... (output CLI sama seperti sebelumnya) ...
            if (empty($result['vulnerabilities'])) {
                echo $result['message'] . " Path: " . $result['filePath'] . "\n";
            } else {
                echo $result['message'] . " Path: " . $result['filePath'] . "\n";
                foreach ($result['vulnerabilities'] as $vulnArray) {
                    echo "----------------------------------------\n";
                    echo "[{$vulnArray['cwe_id']}] Rule: {$vulnArray['rule_name']}\n";
                    echo "Sink: {$vulnArray['sink_function']} | Input: {$vulnArray['vulnerable_input']}\n";
                    echo "File: {$vulnArray['file_path']} (Lines: {$vulnArray['start_line']}-{$vulnArray['end_line']})\n";
                    echo "Snippet:\n{$vulnArray['code_snippet']}\n";
                }
                echo "----------------------------------------\n";
                if ($result['reportPath']) {
                    echo "Heuristic analysis report saved to: {$result['reportPath']}\n";
                }
            }
            break;

        case 'process-repo':
            $result = $appService->handleProcessRepo($options);
            // ... (output CLI sama seperti sebelumnya, termasuk msiComparisonReportPath) ...
            echo "Repository Processing Summary for: {$result['repoUrl']} (TaskID: {$result['taskId']})\n";
            echo "=================================================\n";
            echo "Overall Process Log:\n";
            foreach($result['processLog'] as $logEntry) {
                echo "- {$logEntry}\n";
            }
            echo "-------------------------------------------------\n";
            echo "Vulnerabilities Found: {$result['vulnerabilitiesFound']}\n";
            if ($result['heuristicAnalysisReportPath']) echo "Heuristic Report: {$result['heuristicAnalysisReportPath']}\n";
            if ($result['initialMsi'] !== null) echo "Initial MSI: {$result['initialMsi']}%\n";
            echo "AI Tests Generated: {$result['aiTestsGeneratedCount']}\n";
            echo "AI Tests Selected: {$result['aiTestsSelectedCount']}\n";
            if ($result['finalMsi'] !== null) echo "Final MSI: {$result['finalMsi']}%\n";
            if ($result['msiImprovement'] !== null) echo "MSI Improvement: {$result['msiImprovement']}%\n";
            if ($result['exportedAiTestsPath']) echo "Exported AI Tests: {$result['exportedAiTestsPath']}\n";
            if ($result['msiComparisonReportPath']) echo "MSI Comparison Report: {$result['msiComparisonReportPath']}\n";
            echo "=================================================\n";
            break;

        case null:
            cliShowHelp("No action specified.");
            break;
        default:
            cliShowHelp("Unknown action: " . $action);
            break;
    }
} catch (\InvalidArgumentException $e) {
    $logger->error("Invalid argument: {errorMessage}", ['errorMessage' => $e->getMessage()]);
    cliShowHelp("Error: " . $e->getMessage());
} catch (Throwable $e) {
    $logger->error("Unhandled CLI exception: {errorMessage} at {file}:{line}\nStack Trace:\n{stackTrace}", [
        'errorMessage' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
        'stackTrace' => $_ENV['APP_DEBUG'] === 'true' ? $e->getTraceAsString() : 'Stack trace hidden.'
    ]);
    echo "An critical error occurred. Check logs at {$logFilePath}\n";
    if ($_ENV['APP_DEBUG'] === 'true') {
         echo "Error details: " . $e->getMessage() . "\n";
    }
    exit(1);
}

$logger->info("CLI Application finished action: {action}. TaskID: {$options['taskId']}.", ['action' => $action ?? 'none']);
exit(0);
