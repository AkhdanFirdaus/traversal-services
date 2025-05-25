<?php

// public/server.php (API Only)

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php'; // Sesuaikan path ke autoload

use App\AppService;
use App\Utils\Logger;
use App\Utils\PatternLoader;
use GuzzleHttp\Client as HttpClient;
use Dotenv\Dotenv;
use Psr\Log\LogLevel;

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
$logFilePath = $_ENV['LOG_PATH_API'] ?? __DIR__ . '/../tmp/api.log';
$logLevelName = strtoupper($_ENV['LOG_LEVEL'] ?? 'INFO');
$logLevel = match ($logLevelName) {
    'DEBUG' => Logger::DEBUG, 'WARNING' => Logger::WARNING, 'ERROR' => Logger::ERROR, default => Logger::INFO,
};
$patternsJsonPath = $_ENV['PATTERNS_JSON_PATH'] ?? __DIR__ . '/../config/patterns.json';
$baseCloneDir = $_ENV['BASE_CLONE_DIR_API'] ?? $_ENV['BASE_CLONE_DIR'] ?? __DIR__ . '/../tmp/clones_api';
$baseExportDir = $_ENV['BASE_EXPORT_DIR_API'] ?? $_ENV['BASE_EXPORT_DIR'] ?? __DIR__ . '/../reports/exported_test_cases_api';
$reportsDir = $_ENV['REPORTS_DIR_API'] ?? $_ENV['REPORTS_DIR'] ?? __DIR__ . '/../reports_api';

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

// --- Fungsi Bantuan API (apiSendJsonResponse dan apiHandleGlobalError tetap sama) ---
function apiSendJsonResponse(array $data, int $statusCode = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function apiHandleGlobalError(Throwable $e, Logger $logger): void
{
    $errorDetails = [
        'errorMessage' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'stackTrace' => $_ENV['APP_DEBUG'] === 'true' ? $e->getTraceAsString() : 'Stack trace hidden.'
    ];
    $logger->error("API Unhandled exception: {errorMessage} at {file}:{line}\nStack Trace:\n{stackTrace}", $errorDetails);
    apiSendJsonResponse(
        ['error' => 'An unexpected server error occurred.', 'details' => $_ENV['APP_DEBUG'] === 'true' ? $e->getMessage() : null],
        500
    );
}

// --- Routing dan Penanganan Request API ---
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);

$logger->info("API Request: {method} {path}", ['method' => $requestMethod, 'path' => $path]);

try {
    if ($requestMethod === 'POST' && $path === '/api/analyze') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $options = [];
        if (stripos($contentType, 'application/json') !== false) {
            $jsonInput = file_get_contents('php://input');
            $decodedInput = json_decode($jsonInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedInput)) {
                $options = $decodedInput;
            } else {
                apiSendJsonResponse(['error' => 'Invalid JSON payload.'], 400);
            }
        } else {
            apiSendJsonResponse(['error' => 'Unsupported Content-Type. Please use application/json.'], 415);
        }

        // Tambahkan taskId jika belum ada dari payload, untuk konsistensi
        if (!isset($options['taskId'])) {
            $options['taskId'] = 'api_task_' . uniqid();
        }
        $logger->info("API processing task. TaskID: {taskId}. Options: {options_json}", ['taskId' => $options['taskId'], 'options_json' => json_encode($options)]);


        $actionType = $options['actionType'] ?? null;

        if ($actionType === 'analyze-file') {
            // ... (logika analyze-file sama, AppService yang mengirim update socket) ...
            if (!isset($options['path'])) {
                 apiSendJsonResponse(['error' => "Missing 'path' in JSON payload for analyze-file action."], 400);
            }
            $result = $appService->handleAnalyzeFile($options);
            apiSendJsonResponse($result);

        } elseif ($actionType === 'process-repo') {
            // ... (logika process-repo sama, AppService yang mengirim update socket) ...
             if (!isset($options['url'])) {
                 apiSendJsonResponse(['error' => "Missing 'url' in JSON payload for process-repo action."], 400);
            }
            // PERINGATAN: process-repo bisa sangat lama.
            // Untuk API produksi, ini HARUS dijalankan sebagai background job.
            // Klien API akan menerima taskId dan melakukan polling untuk status/hasil.
            // Untuk demo ini, kita jalankan secara sinkron.
            $logger->info("API: Received process-repo request for URL {repo_url}. TaskID: {taskId}. Processing synchronously (long operation).", [
                'repo_url' => $options['url'],
                'taskId' => $options['taskId']
            ]);
            $result = $appService->handleProcessRepo($options);
            apiSendJsonResponse($result); // Hasilnya akan berisi taskId juga
        } else {
            apiSendJsonResponse(['error' => "Invalid 'actionType' in payload. Supported: 'analyze-file', 'process-repo'."], 400);
        }

    } else {
        apiSendJsonResponse(['error' => 'Endpoint not found or method not supported.'], 404);
    }
} catch (\InvalidArgumentException $e) {
    $logger->warning("API Invalid Argument: {errorMessage}", ['errorMessage' => $e->getMessage()]);
    apiSendJsonResponse(['error' => 'Invalid input.', 'details' => $e->getMessage()], 400);
} catch (Throwable $e) {
    apiHandleGlobalError($e, $logger);
}

