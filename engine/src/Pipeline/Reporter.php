<?php

namespace Pipeline;

use Utils\Logger;
use Utils\SocketNotifier;

class Reporter
{
    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier
    ) {}

    public function generateReports(
        string $repoPath,
        array $vulnerabilities,
        array $initialMsi,
        array $finalMsi,
        string $exportedTestsPath,
        bool $isApi = false
    ): array {
        $this->logger->info("Generating reports");
        $this->notifier->sendUpdate("Generating analysis reports", 97);

        $baseDir = $_ENV['REPORT_DIR'];
        $timestamp = date('Y-m-d_H-i-s');
        $repoName = basename($repoPath);

        // Create report directories
        $reportDir = $this->createReportDirectories($baseDir, $repoName, $timestamp, $isApi);

        // Generate reports
        $reports = [
            'heuristic' => $this->generateHeuristicReport($vulnerabilities, $reportDir),
            'mutation' => $this->generateMutationReport($initialMsi, $finalMsi, $reportDir),
            'summary' => $this->generateSummaryReport([
                'repoPath' => $repoPath,
                'vulnerabilities' => $vulnerabilities,
                'initialMsi' => $initialMsi,
                'finalMsi' => $finalMsi,
                'exportedTestsPath' => $exportedTestsPath
            ], $reportDir)
        ];

        $this->notifier->sendUpdate("Report generation completed", 98);

        return [
            'reportDirectory' => $reportDir,
            'reports' => $reports
        ];
    }

    private function createReportDirectories(string $baseDir, string $repoName, string $timestamp, bool $isApi): string
    {
        $mode = $isApi ? 'api' : 'cli';
        $reportDir = sprintf(
            '%s/%s_%s_%s',
            rtrim($baseDir, '/'),
            $repoName,
            $timestamp,
            $mode
        );

        $dirs = [
            $reportDir,
            $reportDir . '/heuristic_analysis',
            $reportDir . '/msi_reports'
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        return $reportDir;
    }

    private function generateHeuristicReport(array $vulnerabilities, string $reportDir): string
    {
        $reportPath = $reportDir . '/heuristic_analysis/vulnerabilities.json';
        
        $report = [
            'generatedAt' => date('c'),
            'totalVulnerabilities' => 0,
            'vulnerabilitiesByRule' => [],
            'details' => $vulnerabilities
        ];

        // Aggregate statistics
        foreach ($vulnerabilities as $fileVulns) {
            foreach ($fileVulns as $vuln) {
                $report['totalVulnerabilities']++;
                $ruleId = $vuln['ruleId'];
                if (!isset($report['vulnerabilitiesByRule'][$ruleId])) {
                    $report['vulnerabilitiesByRule'][$ruleId] = 0;
                }
                $report['vulnerabilitiesByRule'][$ruleId]++;
            }
        }

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        return $reportPath;
    }

    private function generateMutationReport(array $initialMsi, array $finalMsi, string $reportDir): string
    {
        $reportPath = $reportDir . '/msi_reports/mutation_analysis.json';
        
        $report = [
            'generatedAt' => date('c'),
            'initial' => [
                'score' => $initialMsi['score'],
                'total' => $initialMsi['total'],
                'killed' => $initialMsi['killed'],
                'escaped' => $initialMsi['escaped'],
                'errored' => $initialMsi['errored']
            ],
            'final' => [
                'score' => $finalMsi['score'],
                'total' => $finalMsi['total'],
                'killed' => $finalMsi['killed'],
                'escaped' => $finalMsi['escaped'],
                'errored' => $finalMsi['errored']
            ],
            'improvement' => [
                'score' => $finalMsi['score'] - $initialMsi['score'],
                'additionalKilled' => $finalMsi['killed'] - $initialMsi['killed']
            ]
        ];

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        return $reportPath;
    }

    private function generateSummaryReport(array $data, string $reportDir): string
    {
        $reportPath = $reportDir . '/summary.json';
        
        $report = [
            'generatedAt' => date('c'),
            'repository' => [
                'path' => $data['repoPath'],
                'name' => basename($data['repoPath'])
            ],
            'vulnerabilities' => [
                'total' => array_sum(array_map('count', $data['vulnerabilities'])),
                'files' => count($data['vulnerabilities'])
            ],
            'mutationTesting' => [
                'initialScore' => $data['initialMsi']['score'],
                'finalScore' => $data['finalMsi']['score'],
                'improvement' => $data['finalMsi']['score'] - $data['initialMsi']['score']
            ],
            'generatedTests' => [
                'path' => $data['exportedTestsPath'],
                'directory' => basename($data['exportedTestsPath'])
            ]
        ];

        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        return $reportPath;
    }
} 