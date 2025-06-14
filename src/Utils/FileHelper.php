<?php

declare(strict_types=1);

namespace Utils;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class FileHelper
{
    public static function readFile(string $filePath): string
    {
        if (!is_readable($filePath) || !is_file($filePath)) {
            throw new \RuntimeException("File is not readable");
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("File content not readable");
        }
        return $content;
    }

    public static function writeFile(string $filePath, string $data): bool
    {
        $dir = dirname($filePath);
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) { // Check is_dir again in case of race condition
            throw new \RuntimeException("Failed to create $filePath configuration file");
        }

        if (file_put_contents($filePath, $data) === false) {
            throw new \RuntimeException("Failed to create $filePath configuration file");
        }
        
        return true;
    }

    public static function saveJsonReport(string $filePath, array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        return self::writeFile($filePath, $json);
    }

    public static function saveTestCode(string $targetDir, string $code): string
    {
        $classname = null;

        if (preg_match('/class\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s+extends/', $code, $matches)) {
            $classname = $matches[1];
        }

        // A slightly simpler regex common for typical PHP class names (ASCII only):
        if ($classname === null && preg_match('/class\s+([a-zA-Z_][a-zA-Z0-9_]*)\s+extends/', $code, $matches)) {
            $classname = $matches[1];
        }
        
        $classname = 'Mutated' . $classname;

        $targetDir = $targetDir . '/' . $classname . '.php';
        
        $code = preg_replace('/class\s+(\w+)/', 'class ' . $classname, $code);

        $code = preg_replace('/^```php\s*\n?/i', '', $code);
        
        if (strpos($code, "<?php") !== 0 && preg_match('/^```\s*\n?/', $code)) {
            $code = preg_replace('/^```\s*\n?/i', '', $code);
        }

        if (substr($code, -3) === "```") {
            $code = substr($code, 0, -3);
        }

        $code = trim($code);

        if (file_put_contents($targetDir, $code) === false) {
            throw new \RuntimeException("Failed to write test code to file: $targetDir");
        }

        return $classname;
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

    public static function getProjectStructure(Logger $logger, string $projectDir, string $outputDir, $iterate): mixed {
        $logger->info('AIGenerator: Listing Project Structure using `git ls-files`');

        $process = new Process(['git', 'ls-files'], $projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $target = $outputDir . DIRECTORY_SEPARATOR . "git-lsfiles-output-$iterate.txt";
        $content = $process->getOutput();
        if (file_put_contents($target, $content)) {
            return [
                'path' => $target,
                'content' => $content,
            ];
        } else {
            throw new \Exception('Failed save git ls-files');
        }
    }
}
