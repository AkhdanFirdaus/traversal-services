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

    public static function finalReport(string $reportJson)
    {
        // Load the Infection report
        $report = json_decode(file_get_contents('infection-report.json'), true);

        // Extract MSI and mutation stats
        $msiScore = $report['stats']['msi'] ?? '-';
        $coverage = $report['stats']['mutationCodeCoverage'] ?? '-';
        $killed = $report['stats']['killedCount'] ?? 0;
        $escaped = $report['stats']['escapedCount'] ?? 0;
        $total = $report['stats']['totalMutantsCount'] ?? 0;

        // Initialize test cases from escaped mutants
        $testcases = [];

        foreach ($report['escaped'] as $escapedMutant) {
            $mutator = $escapedMutant['mutator'];
            $filePath = ltrim($mutator['originalFilePath'], '/app/');
            $line = $mutator['originalStartLine'];
            $badCode = trim($mutator['originalSourceCode']);
            $fixedCode = trim($mutator['mutatedSourceCode']);

            $testcases[] = [
                'file' => $filePath,
                'line' => $line,
                'description' => $mutator['mutatorName'] . ' mutation escaped',
                'badCode' => $badCode,
                'fixedCode' => $fixedCode,
            ];
        }

        // Final report structure
        $finalReport = [
            'msi_score' => number_format($msiScore, 2) . '%',
            'msi_score_after' => '-',
            'detail' => [
                'coverage' => number_format($coverage, 2) . '%',
                'kill' => $killed,
                'unkill' => $escaped,
                'total' => $total
            ],
            'testcases' => $testcases,
            'download_path' => 'https://securegen/files/testcase-traversal.zip',
        ];

        return $finalReport;
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
        foreach ($changeLines as $index => $originalLine) {
            $prefix = $originalLine[0];
            $finalLines[] = $prefix . ' ' . $cleanedLines[$index];
        }


        return implode("\n", $finalLines);
    }
}
