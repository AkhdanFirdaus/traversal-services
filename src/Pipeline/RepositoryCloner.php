<?php

namespace Pipeline;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RepositoryCloner
{
    private string $tmpDirectory;

    public function __construct(private string $url, private string $roomName) {}

    public function run(): void {
        try {
            $this->createTemporaryDirectory();
            $this->clone($this->url);
            $this->installDependencies();
        } catch (\Throwable $th) {
            throw new \RuntimeException("Error: " . $th->getMessage());
        }
    }

    private function createTemporaryDirectory(): void
    {
        $tempBase = sys_get_temp_dir();
        $tempDir = $tempBase . DIRECTORY_SEPARATOR . $this->roomName;

        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            throw new \RuntimeException("Failed to create temporary directory: $tempDir");
        }

        $this->tmpDirectory = $tempDir;
    }

    public function deleteTempDirectory() {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->is_dir() ?rmdir($file) : unlink($file);
        }

        rmdir($this->tmpDirectory);
    }

    private function clone(string $url): void
    {
        $process = new Process(['git', 'clone', $url, $this->tmpDirectory]);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function installDependencies(): void
    {
        $process = new Process(['composer', 'install', '--no-interaction', '--optimize-autoloader', '--no-progress'], $this->tmpDirectory);
        $process->setTimeout(3600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    public function getTempDirectory() {
        return $this->tmpDirectory;
    }

} 