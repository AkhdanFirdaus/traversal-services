<?php

declare(strict_types=1);

namespace App\Utils;

class PatternLoader
{
    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Loads vulnerability patterns from a JSON file.
     *
     * @param string $jsonFilePath Path to the JSON file containing pattern definitions.
     * @return array An array of pattern definitions, or an empty array on error.
     * Each pattern definition is expected to be an associative array.
     */
    public function loadPatterns(string $jsonFilePath): array
    {
        $this->logger?->info("Attempting to load patterns from: {filePath}", ['filePath' => $jsonFilePath]);

        if (!is_readable($jsonFilePath) || !is_file($jsonFilePath)) {
            $this->logger?->error("Pattern file not found or not readable: {filePath}", ['filePath' => $jsonFilePath]);
            return [];
        }

        $jsonContent = file_get_contents($jsonFilePath);
        if ($jsonContent === false) {
            $this->logger?->error("Could not read pattern file content: {filePath}", ['filePath' => $jsonFilePath]);
            return [];
        }

        $patterns = json_decode($jsonContent, true); // true for associative array

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger?->error("Failed to decode JSON from pattern file: {filePath}. Error: {jsonError}", [
                'filePath' => $jsonFilePath,
                'jsonError' => json_last_error_msg()
            ]);
            return [];
        }

        if (!is_array($patterns)) {
            $this->logger?->error("Decoded JSON from pattern file is not an array: {filePath}", ['filePath' => $jsonFilePath]);
            return [];
        }

        $this->logger?->info("Successfully loaded {count} pattern definitions from: {filePath}", [
            'count' => count($patterns),
            'filePath' => $jsonFilePath
        ]);
        
        return $patterns;
    }
}
