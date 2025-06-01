<?php

namespace Pipeline;

use AST\TraversalVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\Parser;
use Utils\Logger;
use Utils\SocketNotifier;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class HeuristicAnalyzer
{
    private Parser $parser;
    private NodeTraverser $traverser;
    private TraversalVisitor $visitor;

    public function __construct(
        private Logger $logger,
        private SocketNotifier $notifier
    ) {
        $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        $this->traverser = new NodeTraverser();
        $this->visitor = new TraversalVisitor();
        $this->traverser->addVisitor($this->visitor);
    }

    public function analyze(string $targetDir): array
    {
        $this->logger->info("Starting heuristic analysis", ['dir' => $targetDir]);
        $this->notifier->sendUpdate("Starting code analysis", 25);

        $vulnerabilities = [];
        $fileCount = 0;
        $analyzedCount = 0;

        // Find all PHP files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDir)
        );

        // First count total PHP files
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $fileCount++;
            }
        }

        // Reset iterator
        $iterator->rewind();

        // Analyze each PHP file
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $analyzedCount++;
                $progress = 25 + (($analyzedCount / $fileCount) * 25);
                // $this->notifier->sendUpdate(
                //     "Analyzing file {$analyzedCount} of {$fileCount}",
                //     (int)$progress
                // );

                try {
                    $code = file_get_contents($file->getPathname());
                    $ast = $this->parser->parse($code);
                    
                    if ($ast !== null) {
                        // Reset visitor state
                        $this->visitor->reset();
                        
                        // Traverse the AST
                        $this->traverser->traverse($ast);
                        
                        // Get vulnerabilities found in this file
                        $fileVulnerabilities = $this->visitor->getVulnerabilities();
                        
                        if (!empty($fileVulnerabilities)) {
                            $vulnerabilities[$file->getPathname()] = array_map(
                                fn($v) => $v->toArray(),
                                $fileVulnerabilities
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning("Failed to analyze file", [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logger->info("Heuristic analysis completed", [
            'filesAnalyzed' => $analyzedCount,
            'vulnerabilitiesFound' => count($vulnerabilities)
        ]);

        $this->notifier->sendUpdate("Code analysis completed", 50);

        return $vulnerabilities;
    }
} 