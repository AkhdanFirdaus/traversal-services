#!/usr/bin/env php
<?php

// bin/run.php (CLI Only)

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\AppService;
use App\Utils\Logger;
use App\Utils\PatternLoader;
use App\Utils\FileHelper;
use GuzzleHttp\Client as HttpClient;
use Dotenv\Dotenv;

// --- Muat Variabel Environment ---
try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    echo "Warning: .env file not found. Relying on system environment variables or defaults.\n";
}

// --- Konfigurasi dari Environment Variables ---
$logFilePath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../tmp/app.log';
$logLevelName = strtoupper($_ENV['LOG_LEVEL'] ?? 'INFO');
$logLevel = match ($logLevelName) {
    'DEBUG' => Logger::DEBUG, 'WARNING' => Logger::WARNING, 'ERROR' => Logger::ERROR, default => Logger::INFO,
};
$patternsJsonPath = $_ENV['PATTERNS_JSON_PATH'] ?? __DIR__ . '/../config/patterns.json';
$baseCloneDir = $_ENV['BASE_CLONE_DIR'] ?? __DIR__ . '/../tmp/clones';
$baseExportDir = $_ENV['BASE_EXPORT_DIR'] ?? __DIR__ . '/../reports/exported_test_cases';
$reportsDir = $_ENV['REPORTS_DIR'] ?? __DIR__ . '/../reports';
$llmApiKey = $_ENV['LLM_API_KEY'] ?? '';
$llmModelName = $_ENV['LLM_MODEL_NAME'] ?? 'gemini-2.0-flash';

// --- Inisialisasi Komponen ---
$logger = new Logger($logFilePath, $logLevel);
$patternLoader = new PatternLoader($logger);
$httpClient = new HttpClient(['timeout' => (float)($_ENV['HTTP_CLIENT_TIMEOUT'] ?? 60.0)]);

$appService = new AppService(
    $logger, $patternLoader, $httpClient,
    $patternsJsonPath, $baseCloneDir, $baseExportDir, $reportsDir,
    $llmApiKey, $llmModelName
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

$logger->info("CLI Application started. Action: {action}. Options: {options_json}", [
    'action' => $action ?? 'none', 'options_json' => json_encode($options)
]);

// --- Fungsi Bantuan CLI ---
function cliShowHelp(string $errorMessage = ''): void
{
    if ($errorMessage) echo "Error: {$errorMessage}\n\n";
    echo "PHP Test Case Generator CLI\n";
    echo "Usage: php bin/run.php <command> [options]\n\n";
    echo "Commands:\n";
    echo "  analyze-file --path=<file_path.php>   Analyzes a single PHP file.\n";
    echo "  process-repo --url=<git_repo_url>     Processes a full repository.\n";
    echo "                                        [--branch=<branch_name>]\n";
    echo "                                        [--infection-opts=\"--min-msi=50 --threads=2\"]\n";
    echo "\n";
    exit($errorMessage ? 1 : 0);
}

// --- Dispatcher Perintah CLI ---
try {
    switch ($action) {
        case 'analyze-file':
            $result = $appService->handleAnalyzeFile($options);
            if (empty($result['vulnerabilities'])) {
                echo $result['message'] . " Path: " . $result['filePath'] . "\n";
            } else {
                echo $result['message'] . " Path: " . $result['filePath'] . "\n";
                foreach ($result['vulnerabilities'] as $vulnArray) {
                    // Buat instance VulnerabilityLocation untuk menggunakan __toString atau format manual
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
            echo "Repository Processing Summary for: {$result['repoUrl']}\n";
            echo "-------------------------------------------------\n";
            foreach($result['processLog'] as $logEntry) {
                echo "- {$logEntry}\n";
            }
            echo "-------------------------------------------------\n";
            echo "Vulnerabilities Found: {$result['vulnerabilitiesFound']}\n";
            if ($result['heuristicAnalysisReport']) echo "Heuristic Report: {$result['heuristicAnalysisReport']}\n";
            if ($result['initialMsi'] !== null) echo "Initial MSI: {$result['initialMsi']}%\n";
            echo "AI Tests Generated: {$result['aiTestsGeneratedCount']}\n";
            echo "AI Tests Selected: {$result['aiTestsSelectedCount']}\n";
            if ($result['finalMsi'] !== null) echo "Final MSI (Simulated): {$result['finalMsi']}%\n";
            if ($result['exportedAiTestsPath']) echo "Exported AI Tests: {$result['exportedAiTestsPath']}\n";
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

$logger->info("CLI Application finished action: {action}.", ['action' => $action ?? 'none']);
exit(0);

