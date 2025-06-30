<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\AppService;
use Utils\Logger;

// Ensure we have the repository URL argument
if ($argc < 2) {
    echo "Usage: php run.php <repository-url>\n";
    exit(1);
}

$repoUrl = $argv[1];
$roomName = uniqid();

$logger = new Logger(false);

try {
    $app = new AppService($logger);
    $results = $app->handleProcessRepo($repoUrl, $roomName);
} catch (\Throwable $th) {
    echo $th->getMessage();
    echo $th->getTrace();
}
