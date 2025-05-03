<?php

namespace Engine;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Engine\Utils;

class TraversalVisitor extends NodeVisitorAbstract {
    private array $patterns;
    private array $findings = [];
    
    public function __construct(array $patterns) {
        $this->patterns = $patterns;
    }

    public function enterNode(Node $node) {
        if ($node instanceof Node\Expr\FuncCall) {
            $funcName = $node->name instanceof Node\Name ? $node->name->toString() : null;
            foreach ($node->args as $arg) {
                $code = $this->prettyPrintExpr($arg->value);
                foreach ($this->patterns as $patternGroup) {
                    foreach ($patternGroup['patterns'] as $testPattern) {
                        if (strpos($code, $testPattern) !== false) {
                            $this->findings[] = [
                                'function' => $funcName,
                                'argument' => $code,
                                'matched_pattern' => $testPattern,
                                'cwe' => $patternGroup['cwe'],
                                'description' => $patternGroup['name'],
                                'line' => $node->getLine(),
                            ];
                        }
                    }
                }
            }
        }
    }

    public function getFindings(): array {
        return $this->findings;
    }

    public function prettyPrintExpr(Node $node): string {
        $printer = new Standard();
        return $printer->prettyPrintExpr($node);
    }
}


class Detector {
    public static function loadPatterns(): array {
        $path = __DIR__ . '../../materials/patterns.json';
        return json_decode(file_get_contents($path), true);
    }

    public static function detectTraversalRisks(array $phpFiles): array {
        $vulns = [];
    
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
    
            if (preg_match('/\$_(GET|POST|REQUEST)/', $content) &&
                preg_match('/(include|require|fopen|file_get_contents|readfile)/', $content)) {
                $vulns[] = ['file' => $file, 'content' => $content];
            }
        }
    
        return $vulns;
    }

    public static function detect(array $filePaths): array {
        $vulnerabilities = [];
        $errors = [];

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        
        $visitor = new TraversalVisitor(self::loadPatterns());
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        foreach ($filePaths as $filePath) {
            $code = file_get_contents($filePath);
            
            try {
                $ast = $parser->parse($code);
                $traverser->traverse($ast);

                if (!empty($visitor->getFindings())) {
                    $vulnerabilities[] = [
                        'file' => $filePath,
                        'findings' => $visitor->getFindings(),
                    ];
                }
            } catch (\PhpParser\Error $e) {
                $errors[] = [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ];
            }
        }

        if (!empty($errors)) {
            Utils::saveReport('errors', $errors);
        }
        
        return $vulnerabilities;
    }
}