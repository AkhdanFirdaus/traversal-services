<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/analyze') {
    $data = json_decode(file_get_contents('php://input'), true);

    echo $data;
    
    $gitUrl = escapeshellarg($data['url'] ?? '');

    $output = [];
    $status = 0;

    exec("php run.php {$gitUrl}", $output, $status);

    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'output' => implode("\n", $output),
    ]);
    exit;
}
http_response_code(404);
echo "Not Found";
