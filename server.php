<?php
require __DIR__ . '/vendor/autoload.php';

use Workerman\Worker;
use App\CustomServer;

// Configure logging
Worker::$logFile = __DIR__ . '/socket-logs/socketio.log';
Worker::$stdoutFile = __DIR__ . '/socket-logs/debug.log';
file_put_contents(Worker::$logFile, "=== Starting Server ===\n", FILE_APPEND);

$port = 2021;
$server = new CustomServer($port);
$server->createServer();

$io = $socket->getServer();

echo "Socket.IO server running on port $port\n";

Worker::runAll($io);