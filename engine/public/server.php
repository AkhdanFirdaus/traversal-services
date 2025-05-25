<?php

// public/server.php (API Only)

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php'; // Sesuaikan path ke autoload

use App\AppService;
use App\Utils\Logger;
use App\Utils\PatternLoader;
use GuzzleHttp\Client as HttpClient;
use Dotenv\Dotenv;

// --- Muat Variabel Environment ---
try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    // error_log("Warning: .env file not found for API server.");
}

// --- Konfigurasi dari Environment Variables ---
$logFilePath = $_ENV['LOG_PATH_API'] ?? __DIR__ . '/../tmp/api.log'; // Log API terpisah
$logLevelName = strtoupper($_ENV['LOG_LEVEL'] ?? 'INFO');
$logLevel = match ($logLevelName) {
    'DEBUG' => Logger::DEBUG, 'WARNING' => Logger::WARNING, 'ERROR' => Logger::ERROR, default => Logger::INFO,
};
$patternsJsonPath = $_ENV['PATTERNS_JSON_PATH'] ?? __DIR__ . '/../config/patterns.json';
$baseCloneDir = $_ENV['BASE_CLONE_DIR_API'] ?? __DIR__ . '/../tmp/clones_api';
$baseExportDir = $_ENV['BASE_EXPORT_DIR_API'] ?? __DIR__ . '/../reports/exported_test_cases_api';
$reportsDir = $_ENV['REPORTS_DIR_API'] ?? __DIR__ . '/../reports_api'; // Direktori laporan API terpisah
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

// --- Fungsi Bantuan API ---
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
    if ($requestMethod === 'POST' && $path === '/api/analyze') { // Endpoint utama
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

        $actionType = $options['actionType'] ?? null;

        if ($actionType === 'analyze-file') {
            if (!isset($options['path'])) {
                 apiSendJsonResponse(['error' => "Missing 'path' in JSON payload for analyze-file action."], 400);
            }
            // PERINGATAN KEAMANAN: Validasi $options['path'] secara ketat di sini!
            // Jangan biarkan path arbitrer. Misalnya, pastikan path berada dalam direktori yang diizinkan.
            // Atau, API hanya menerima ID file yang sudah diunggah/terdaftar.
            // $safePath = realpath($options['path']);
            // if (!$safePath || strpos($safePath, realpath(__DIR__ . '/../tmp/analysis_targets/')) !== 0) { // Contoh validasi
            //     apiSendJsonResponse(['error' => "Invalid or unauthorized file path."], 400);
            // }
            $result = $appService->handleAnalyzeFile(['path' => $options['path']]); // Gunakan $safePath jika diimplementasikan
            apiSendJsonResponse($result);

        } elseif ($actionType === 'process-repo') {
             if (!isset($options['url'])) {
                 apiSendJsonResponse(['error' => "Missing 'url' in JSON payload for process-repo action."], 400);
            }
            // PERINGATAN: process-repo bisa sangat lama. Tidak ideal untuk request API sinkron.
            // Pertimbangkan antrian pekerjaan (job queue) untuk ini.
            $logger->info("API: Received process-repo request for URL {repo_url}. Processing synchronously (long operation).", ['repo_url' => $options['url']]);
            $result = $appService->handleProcessRepo($options);
            apiSendJsonResponse($result);
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
