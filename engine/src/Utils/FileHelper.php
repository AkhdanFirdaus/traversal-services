<?php

declare(strict_types=1);

namespace Utils;

class FileHelper
{
    /**
     * Reads the content of a file.
     *
     * @param string $filePath Path to the file.
     * @param Logger|null $logger Optional logger instance.
     * @return string|null File content or null on failure.
     */
    public static function readFile(string $filePath, ?Logger $logger = null): string
    {
        if (!is_readable($filePath) || !is_file($filePath)) {
            $logger?->warning("File not found or not readable: {filePath}", ['filePath' => $filePath]);
            throw new \RuntimeException("Failed to create Infection configuration file");
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            $logger?->error("Could not read file content: {filePath}", ['filePath' => $filePath]);
            throw new \RuntimeException("Failed to create Infection configuration file");
        }
        return $content;
    }

    /**
     * Writes data to a file. Creates directory if it doesn't exist.
     *
     * @param string $filePath Path to the file.
     * @param string $data Data to write.
     * @param Logger|null $logger Optional logger instance.
     * @return bool True on success, false on failure.
     */
    public static function writeFile(string $filePath, string $data, ?Logger $logger = null): bool
    {
        $dir = dirname($filePath);
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) { // Check is_dir again in case of race condition
            $logger?->error("Failed to create directory: {directoryPath}", ['directoryPath' => $dir]);
            throw new \RuntimeException("Failed to create Infection configuration file");
        }

        if (file_put_contents($filePath, $data) === false) {
            $logger?->error("Failed to write to file: {filePath}", ['filePath' => $filePath]);
            throw new \RuntimeException("Failed to create Infection configuration file");
        }
        
        $logger?->debug("Successfully wrote to file: {filePath}", ['filePath' => $filePath]);
        return true;
    }

    /**
     * Saves array data as a JSON report.
     *
     * @param string $filePath Path to the JSON file.
     * @param array $data Data to encode and save.
     * @param Logger|null $logger Optional logger instance.
     * @return bool True on success, false on failure.
     */
    public static function saveJsonReport(string $filePath, array $data, ?Logger $logger = null): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $logger?->error("Failed to encode data to JSON for report: {filePath}. Error: {jsonError}", [
                'filePath' => $filePath,
                'jsonError' => json_last_error_msg()
            ]);
            return false;
        }
        return self::writeFile($filePath, $json, $logger);
    }

    /**
     * Recursively deletes a directory.
     *
     * @param string $dirPath Path to the directory.
     * @param Logger|null $logger
     * @return bool True on success, false on failure.
     */
    public static function deleteDirectoryRecursive(string $dirPath, ?Logger $logger = null): bool
    {
        if (!is_dir($dirPath)) {
            $logger?->debug("Directory not found, no need to delete: {dirPath}", ['dirPath' => $dirPath]);
            return true; // Or false if it's an error for it not to exist
        }
        $logger?->info("Attempting to delete directory recursively: {dirPath}", ['dirPath' => $dirPath]);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            if (!$todo($fileinfo->getRealPath())) {
                $logger?->error("Failed to {action} {path}", ['action' => $todo, 'path' => $fileinfo->getRealPath()]);
                return false; // Abort on first failure
            }
        }

        if (!rmdir($dirPath)) {
            $logger?->error("Failed to remove the main directory: {dirPath}", ['dirPath' => $dirPath]);
            return false;
        }
        $logger?->info("Successfully deleted directory: {dirPath}", ['dirPath' => $dirPath]);
        return true;
    }

    /**
     * Creates a temporary directory with a unique name.
     *
     * @param string $baseDir The base directory where the temporary directory will be created.
     * @param string $prefix Prefix for the temporary directory name.
     * @param Logger|null $logger
     * @return string|null Path to the created temporary directory, or null on failure.
     */
    public static function createTemporaryDirectory(string $baseDir, string $prefix = 'tmp_', ?Logger $logger = null): ?string
    {
        // Ensure base directory exists and is writable
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            $logger?->error("Base temporary directory does not exist and cannot be created: {baseDir}", ['baseDir' => $baseDir]);
            return null;
        }
        if (!is_writable($baseDir)) {
             $logger?->error("Base temporary directory is not writable: {baseDir}", ['baseDir' => $baseDir]);
            return null;
        }

        $tempDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . uniqid('', true);
        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            $logger?->error("Failed to create temporary directory: {tempDir}", ['tempDir' => $tempDir]);
            return null;
        }
        $logger?->debug("Created temporary directory: {tempDir}", ['tempDir' => $tempDir]);
        return $tempDir;
    }
}
