<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\AppService;
use Utils\Logger;
use Utils\SocketNotifier;

// Ensure we have the repository URL argument
if ($argc < 2) {
    echo "Usage: php run.php <repository-url>\n";
    exit(1);
}

$repoUrl = $argv[1];
$logger = new Logger();
$socket = new SocketNotifier($logger);

try {
    $app = new AppService($logger, $socket);
    $results = $app->handleProcessRepo($repoUrl);

    echo "Processing completed successfully!\n";
    echo "Results:\n";
    echo "- Initial MSI Score: {$results['initialMsi']['score']}\n";
    echo "- Final MSI Score: {$results['finalMsi']['score']}\n";
    echo "- Exported tests location: {$results['exportedTestsPath']}\n";
    echo "- Found vulnerabilities: " . count($results['vulnerabilities']) . "\n";

    exit(0);
} catch (\Exception $e) {
    $logger->error("Failed to process repository", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 