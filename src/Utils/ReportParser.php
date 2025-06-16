<?php

namespace Utils;

use InvalidArgumentException;

class ReportParser
{
    /**
     * Parses a full Infection JSON report and generates a concise summary.
     *
     * @param string $reportJson The raw JSON content from infection-report.json.
     * @param string $projectDir The absolute path to the project's root directory, used to create relative paths for files.
     * @return string A JSON-formatted string containing the summary.
     * @throws InvalidArgumentException if the JSON is invalid.
     */
    public static function excludingKilled(string $reportJson): string
    {
        $data = json_decode($reportJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON provided for mutation report. Error: ' . json_last_error_msg());
        }

        unset($data['killed']);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function generateMutationSummary(string $reportJson, string $projectDir): string
    {
        $reportData = json_decode($reportJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON provided for mutation report. Error: ' . json_last_error_msg());
        }

        $stats = $reportData['stats'] ?? [];
        $escapedMutants = $reportData['escaped'] ?? [];

        $summary = [
            'msiScore' => $stats['msi'] ?? 0.0,
            'totalMutants' => $stats['totalMutantsCount'] ?? 0,
            'killed' => $stats['killedCount'] ?? 0,
            'escaped' => $stats['escapedCount'] ?? 0,
            'survivingMutantsByFile' => [],
        ];

        foreach ($escapedMutants as $mutant) {
            $mutatorInfo = $mutant['mutator'] ?? [];
            $filePath = $mutatorInfo['originalFilePath'] ?? 'unknown_file';

            // Make the file path relative to the project root
            $relativePath = str_replace($projectDir . '/', '', $filePath);

            if (!isset($summary['survivingMutantsByFile'][$relativePath])) {
                $summary['survivingMutantsByFile'][$relativePath] = [];
            }

            // Clean the diff to match the desired output format
            $cleanedDiff = self::cleanDiff($mutant['diff'] ?? '');

            $summary['survivingMutantsByFile'][$relativePath][] = [
                'line' => $mutatorInfo['originalStartLine'] ?? 0,
                'mutator' => $mutatorInfo['mutatorName'] ?? 'UnknownMutator',
                'diff' => $cleanedDiff,
            ];
        }

        return json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Cleans the diff string from Infection to only include the change lines.
     *
     * @param string $rawDiff
     * @return string
     */
    private static function cleanDiff(string $rawDiff): string
    {
        $lines = explode("\n", $rawDiff);
        $changeLines = array_filter($lines, function ($line) {
            return str_starts_with($line, '+ ') || str_starts_with($line, '- ');
        });

        // Remove the leading '+ ' or '- ' and the first character (space)
        $cleanedLines = array_map(function ($line) {
            return substr($line, 2);
        }, $changeLines);
        
        // Re-add the '+' and '-' prefixes without the extra space
        $finalLines = [];
        foreach($changeLines as $index => $originalLine) {
            $prefix = $originalLine[0];
            $finalLines[] = $prefix . ' ' . $cleanedLines[$index];
        }


        return implode("\n", $finalLines);
    }
}
