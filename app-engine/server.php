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

// function handleUsersRequest($method) {
//     // Mock data (you can replace this with a database connection)
//     $users = [
//         ["id" => 1, "name" => "Alice"],
//         ["id" => 2, "name" => "Bob"],
//         ["id" => 3, "name" => "Charlie"]
//     ];

//     switch ($method) {
//         case 'GET':
//             // Return the list of users
//             echo json_encode($users);
//             break;

//         case 'POST':
//             // Simulate adding a new user
//             $input = json_decode(file_get_contents('php://input'), true);
//             if (isset($input['name'])) {
//                 $newUser = ["id" => count($users) + 1, "name" => $input['name']];
//                 echo json_encode(["message" => "User added", "user" => $newUser]);
//             } else {
//                 http_response_code(400);
//                 echo json_encode(["message" => "Invalid input"]);
//             }
//             break;

//         default:
//             http_response_code(405);
//             echo json_encode(["message" => "Method not allowed"]);
//             break;
//     }
// }
// ?>
