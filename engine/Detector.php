<?php

namespace Engine;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Engine\Utils;


class Detector {
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

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($filePaths as $filePath) {
            try {
                $code = file_get_contents($filePath);
                $ast = $parser->parse($code);

                $traverser = new NodeTraverser();
                $visitor = new class extends NodeVisitorAbstract {
                    public array $risks = [];
                    public function enterNode(Node $node) {
                        if ($node instanceof Node\Expr\Include_
                            || $node instanceof Node\Expr\Eval_
                            || $node instanceof Node\Expr\FuncCall
                            && in_array($node->name, ['file_get_contents', 'fopen'])) {
                        } {
                            if ($node->expr instanceof Node\Expr\ArrayDimFetch) {
                                $var = $node->expr->var;
                                if ($var instanceof Node\Expr\Variable && in_array($var->name, ['_GET', '_POST', '_REQUEST'])) {
                                    $this->risks[] = [
                                        'line' => $node->getStartLine(),
                                        'type' => 'Traversal Risk: ' . get_class($node),
                                        'input' => $var->name,
                                        'raw' => $node->expr,
                                        'message' => 'Potential traversal risk detected.'
                                    ];
                                }
                            }
                        }
                    }
                };

                $traverser->addVisitor(($visitor));
                $traverser->traverse($ast);

                if (!empty($visitor->risks)) {
                    $vulnerabilities[] = [
                        'file' => $filePath,
                        'risks' => $visitor->risks
                    ];
                }
            } catch (Error $e) {
                Utils::saveReport('error', [
                    'file' => $filePath,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $vulnerabilities;
    }
}