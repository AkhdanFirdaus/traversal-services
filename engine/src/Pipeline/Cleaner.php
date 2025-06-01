<?php

namespace Pipeline;

use Utils\Logger;
use Utils\SocketNotifier;

class Cleaner
{
    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier
    ) {}

    public function cleanup(string $repoPath, bool $isApi = false): void
    {
        $this->logger->info("Starting cleanup process", [
            'repoPath' => $repoPath,
            'mode' => $isApi ? 'api' : 'cli'
        ]);
        $this->notifier->sendUpdate("Cleaning up temporary files", 99);

        try {
            // Clean up cloned repository
            $this->removeDirectory($repoPath);

            // Clean up old temporary files
            $this->cleanupOldTempFiles($isApi);

            $this->notifier->sendUpdate("Cleanup completed", 100);
            
        } catch (\Exception $e) {
            $this->logger->error("Cleanup failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $this->logger->info("Removing directory", ['dir' => $dir]);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }

    private function cleanupOldTempFiles(bool $isApi): void
    {
        $tmpDir = $isApi ? $_ENV['TMP_DIR_API'] : $_ENV['TMP_DIR_CLI'];
        if (!is_dir($tmpDir)) {
            return;
        }

        $this->logger->info("Cleaning up old temporary files", ['dir' => $tmpDir]);

        // Get all items in the temp directory
        $items = new \DirectoryIterator($tmpDir);
        $now = time();
        $maxAge = 24 * 3600; // 24 hours

        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }

            $path = $item->getRealPath();
            $age = $now - $item->getMTime();

            // Remove files/directories older than maxAge
            if ($age > $maxAge) {
                if ($item->isDir()) {
                    $this->removeDirectory($path);
                } else {
                    unlink($path);
                }

                $this->logger->info("Removed old temporary item", [
                    'path' => $path,
                    'age' => round($age / 3600, 1) . ' hours'
                ]);
            }
        }
    }
} 