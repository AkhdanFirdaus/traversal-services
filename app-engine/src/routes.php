<?php

use App\Main;

$method = $_SERVER['REQUEST_METHOD'];
$request = explode('/', trim($_SERVER['REQUEST_URI'], '/'));

if ($request[0] === 'analyze') {
    switch ($method) {
        case 'GET':
            echo json_encode([
                'message' => 'Hello World',
                'status' => 'success',
            ]);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['url'])) {
                $gitUrl = escapeshellarg($input['url']);

                $result = Main::runApi($gitUrl);

                echo json_encode([
                    "message" => "Url identified: " . $gitUrl, 
                    "result" => $result,
                    "status" => 'success',
                ]);
            } else {
                http_response_code(400);
                echo json_encode(["message" => "Invalid input"]);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(["message" => "Method not allowed"]);
            break;
    }
} else {
    http_response_code(404);
    echo json_encode(["message" => "Endpoint not found"]);
}