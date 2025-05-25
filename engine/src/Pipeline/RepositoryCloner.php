<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Utils\Logger;
use App\Utils\FileHelper;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class RepositoryCloner
{
    private ?Logger $logger;
    private string $baseCloneDir; // Base directory where repos will be cloned into subdirs

    public function __construct(?Logger $logger = null, string $baseCloneDir = 'tmp/clones')
    {
        $this->logger = $logger;
        $this->baseCloneDir = rtrim($baseCloneDir, DIRECTORY_SEPARATOR);
    }

    /**
     * Clones a Git repository to a unique temporary directory.
     *
     * @param string $repositoryUrl The URL of the Git repository.
     * @param string|null $branch Optional branch name to clone.
     * @param int $depth Optional clone depth (for shallow clones).
     * @return string|null The path to the cloned repository on success, null on failure.
     */
    public function clone(string $repositoryUrl, ?string $branch = null, ?int $depth = null): ?string
    {
        // Sanitize repo URL slightly to create a somewhat safe directory name part
        $repoNamePart = preg_replace('/[^a-zA-Z0-9_-]/', '_', basename($repositoryUrl, '.git'));
        $uniqueDirName = $repoNamePart . '_' . uniqid();
        $clonePath = $this->baseCloneDir . DIRECTORY_SEPARATOR . $uniqueDirName;

        if (!FileHelper::deleteDirectoryRecursive($clonePath, $this->logger)) { // Clean up if exists
             $this->logger?->warning("Could not clean up existing directory before cloning: {clonePath}", ['clonePath' => $clonePath]);
             // Continue, git clone might handle it or fail
        }

        if (!is_dir(dirname($clonePath))) {
            if (!mkdir(dirname($clonePath), 0775, true) && !is_dir(dirname($clonePath))) {
                $this->logger?->error("Failed to create base clone directory: {baseCloneDir}", ['baseCloneDir' => dirname($clonePath)]);
                return null;
            }
        }
        
        $this->logger?->info("Attempting to clone {repositoryUrl} into {clonePath}", [
            'repositoryUrl' => $repositoryUrl,
            'clonePath' => $clonePath
        ]);

        $command = ['git', 'clone'];
        if ($branch) {
            $command[] = '--branch';
            $command[] = $branch;
        }
        if ($depth) {
            $command[] = '--depth';
            $command[] = (string)$depth;
        }
        $command[] = $repositoryUrl;
        $command[] = $clonePath; // Target directory for clone

        $process = new Process($command);
        $process->setTimeout(300); // 5 minutes timeout for cloning

        try {
            $process->mustRun();
            $this->logger?->info("Successfully cloned repository: {repositoryUrl} to {clonePath}", [
                'repositoryUrl' => $repositoryUrl,
                'clonePath' => $clonePath
            ]);
            return $clonePath;
        } catch (ProcessFailedException $exception) {
            $this->logger?->error("Failed to clone repository: {repositoryUrl}. Error: {errorMessage}", [
                'repositoryUrl' => $repositoryUrl,
                'errorMessage' => $exception->getMessage(),
                'stderr' => $process->getErrorOutput(),
                'stdout' => $process->getOutput(),
            ]);
            // Attempt to clean up failed clone directory
            FileHelper::deleteDirectoryRecursive($clonePath, $this->logger);
            return null;
        } catch (\Throwable $e) {
            $this->logger?->error("An unexpected error occurred during cloning of {repositoryUrl}: {errorMessage}", [
                'repositoryUrl' => $repositoryUrl,
                'errorMessage' => $e->getMessage()
            ]);
            FileHelper::deleteDirectoryRecursive($clonePath, $this->logger);
            return null;
        }
    }

    /**
     * Cleans up (deletes) a cloned repository directory.
     *
     * @param string $clonePath The path to the cloned repository.
     * @return bool True on success, false on failure.
     */
    public function cleanup(string $clonePath): bool
    {
        $this->logger?->info("Cleaning up cloned repository at: {clonePath}", ['clonePath' => $clonePath]);
        return FileHelper::deleteDirectoryRecursive($clonePath, $this->logger);
    }
}
