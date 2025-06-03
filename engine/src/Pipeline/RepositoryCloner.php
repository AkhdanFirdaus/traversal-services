<?php

namespace Pipeline;

use Symfony\Component\Process\Process;
use Utils\Logger;
use Utils\SocketNotifier;

class RepositoryCloner
{
    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier
    ) {}

    public function clone(string $repoUrl, bool $isApi = false): string
    {
        $this->logger->info("Starting repository clone", ['url' => $repoUrl]);
        $this->notifier->sendUpdate("Cloning repository", 5);

        // Determine target directory
        $baseDir = $isApi ? $_ENV['TMP_DIR_API'] : $_ENV['TMP_DIR_CLI'];
        $repoName = $this->extractRepoName($repoUrl);
        $targetDir = $baseDir . '/' . $repoName . '_' . uniqid();

        // Ensure target directory exists
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        // Clone the repository
        $command = sprintf('git clone %s %s', escapeshellarg($repoUrl), escapeshellarg($targetDir));
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            $error = implode("\n", $output);
            $this->logger->error("Failed to clone repository", [
                'url' => $repoUrl,
                'error' => $error
            ]);
            throw new \RuntimeException("Failed to clone repository: " . $error);
        }

        $this->notifier->sendUpdate("Repository cloned successfully", 10);

        // Install dependencies
        $this->installDependencies($targetDir);

        return $targetDir;
    }

    private function extractRepoName(string $url): string
    {
        // Remove .git extension if present
        $url = preg_replace('/\.git$/', '', $url);
        
        // Extract the last part of the path
        $parts = explode('/', rtrim($url, '/'));
        return end($parts);
    }

    private function installDependencies(string $targetDir): void
    {
        $this->logger->info("Installing dependencies", ['dir' => $targetDir]);
        $this->notifier->sendUpdate("Installing dependencies", 15);

        // Change to target directory
        $currentDir = getcwd();
        chdir($targetDir);

        try {
            // Install production dependencies
            $command = ['composer', 'install', '--no-dev', '--no-interaction', '--optimize-autoloader'];

            $process = new Process($command);
            $process->setTimeout(3600);
            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger->warning("Failed to install production dependencies", [
                    'output' => $process->getErrorOutput()
                ]);
            }

            // Install PHPUnit if not present
            if (!file_exists('vendor/bin/phpunit')) {
                $command = ['composer', 'require', '--dev', 'phpunit/phpunit', '--no-interaction'];
                $process = new Process($command);
                $process->setTimeout(3600);
                $process->run();

                if (!$process->isSuccessful()) {
                    $this->logger->warning("Failed to install PHPUnit", [
                        'output' => $process->getErrorOutput()
                    ]);
                }
            }

            $this->notifier->sendUpdate("Dependencies installed", 20);

        } finally {
            // Restore original directory
            chdir($currentDir);
        }
    }
} 