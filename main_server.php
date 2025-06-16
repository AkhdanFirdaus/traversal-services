<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\AppService;
use Utils\Logger;
use Utils\SocketNotifier;

// Set environment for API mode
$_ENV['APP_ENV'] = 'api';

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

function respondAndContinue(array $response) {
    // End any existing buffer
    while (ob_get_level()) {
        ob_end_clean();
    }

    ignore_user_abort(true);
    set_time_limit(0);

    $json = json_encode($response);

    header('Connection: close');
    header('Content-Encoding: none'); // Avoid gzip buffering
    header('Content-Length: ' . strlen($json));

    echo $json;

    // Ensure output is sent
    flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // For PHP-FPM
    }
}

function processingProject() {
    
    try {
        // Get JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['gitUrl'])) {
            throw new \InvalidArgumentException('Repository URL is required');
        }
        
        if (!isset($input['roomName'])) {
            throw new \InvalidArgumentException('Room Name is required');
        }

        $gitUrl = $input['gitUrl'];
        $roomName = $input['roomName'];
        
        $logger = new Logger();
        $socket = new SocketNotifier($logger, $roomName);

        $app = new AppService($logger, $socket);
        $results = $app->handleProcessRepo(
            $gitUrl,
            $roomName, 
        );

        echo json_encode([
            'success' => true,
            'status' => 'success',
            'message' => 'Task Started',
        ]);

    } catch (\InvalidArgumentException $e) {
        $logger->warning('Invalid request', ['error' => $e->getMessage()]);
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'status' => 'success',
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
            'status' => 'success',
            'error' => 'Internal server error'
        ]);
    }
}

switch ($requestUri) {
    case '/test':
        echo json_encode([
            'status' => 'success',
            'status' => 'success',
            'message' => 'API is working properly.',
        ]);
        break;
    case '/process':
        // Basic CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($requestMethod !== 'POST') {
            http_response_code(405);
            echo json_encode([
                'status' => 'error',
                'message' => 'Only POST method is allowed on /process.'
            ]);
            break;
        }
        
        respondAndContinue([
            'status' => 'accepted',
            'message' => 'Processing started in background.'
        ]);

        processingProject();
        break;
    default:
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Endpoint not found.'
        ]);
        break;
}
