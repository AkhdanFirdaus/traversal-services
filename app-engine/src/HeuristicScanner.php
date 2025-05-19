<?php

namespace App;

use App\Helpers\Utils;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

class HeuristicScanner {
    public function __construct(private string $repoDir, private string $reportDir) {
        @mkdir($reportDir, 0777, true);
    }

    public function run(): array {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $results = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repoDir));
        foreach ($iterator as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $content = file_get_contents($file);
                try {
                    $ast = $parser->parse($content);
                    $traverser = new NodeTraverser();
                    $visitor = new class extends NodeVisitorAbstract {
                        public array $vulnerable = [];
                        public function enterNode(Node $node) {
                            if ($node instanceof Node\Expr\Include_ ||
                                $node instanceof Node\Expr\FuncCall &&
                                in_array($node->name instanceof Node\Name ? $node->name->toString() : '', ['file_get_contents', 'fopen', 'readfile'])) {
                                if ($node->expr instanceof Node\Expr\Variable || $node->args[0]->value instanceof Node\Expr\Variable) {
                                    $this->vulnerable[] = [
                                        'line' => $node->getStartLine(),
                                        'type' => 'file inclusion or usage',
                                    ];
                                }
                            }
                        }
                    };
                    $traverser->addVisitor($visitor);
                    $traverser->traverse($ast);
                    if (!empty($visitor->vulnerable)) {
                        $results[] = [
                            'file' => str_replace($this->repoDir . '/', '', $file),
                            'issues' => $visitor->vulnerable,
                            'code_snippet' => substr($content, 0, 500),
                            'vulnerability_type' => 'Directory Traversal'
                        ];
                    }
                } catch (\Exception $e) {}
            }
        }
        file_put_contents("$this->reportDir/heuristic_report.json", json_encode($results, JSON_PRETTY_PRINT));
        
        Utils::log("2. heuristic_report", json_encode($results, JSON_PRETTY_PRINT));
        
        return $results;
    }
}