<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\AppService;
use Utils\Logger;
use Utils\SocketNotifier;

// Set environment for API mode
$_ENV['APP_ENV'] = 'api';

// Basic CORS headers
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$logger = new Logger();
$socket = new SocketNotifier($logger);

try {
    // Get JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['repositoryUrl'])) {
        throw new \InvalidArgumentException('Repository URL is required');
    }

    $app = new AppService($logger, $socket);
    $results = $app->handleProcessRepo($input['repositoryUrl'], true);

    echo json_encode([
        'success' => true,
        'data' => $results
    ]);

} catch (\InvalidArgumentException $e) {
    $logger->warning('Invalid request', ['error' => $e->getMessage()]);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

} catch (\Exception $e) {
    $logger->error('Server error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
} 