<?php

namespace App\Pipeline;

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

class Analyzer {
    public static function analyzeSourceCode(string $dir): array {
        $phpFiles = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if (!$file->isDir() && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }
        return $phpFiles;
    }

    public static function analyzeTestCases(string $dir): array {
        $phpFiles = self::analyzeSourceCode($dir);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $results = [];

        foreach ($phpFiles as $file) {
            $code = file_get_contents($file);
            try {
                $ast = $parser->parse($code);
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new class extends NodeVisitorAbstract {
                    public $isTestRef;

                    public function __construct(&$isTestRef) {
                        $this->isTestRef = &$isTestRef;
                    }

                    public function enterNode(Node $node) {
                        if ($node instanceof Node\Stmt\Class_ && $node->extends) {
                            $parent = $node->extends->toString();
                            if (strtolower($parent) === 'phpunit\\framework\\testcase' || $parent === 'TestCase') {
                                $this->isTestRef = true;
                                return NodeVisitor::STOP_TRAVERSAL;
                            }
                        }

                        if ($node instanceof Node\Stmt\ClassMethod && strpos($node->name->toString(), 'test') === 0) {
                            $this->isTestRef = false;
                            return NodeVisitor::STOP_TRAVERSAL;
                        }

                        return null;
                    }
                });

                $traverser->traverse($ast);
                $results[] = ['file' => $file, 'ast' => $ast];
            } catch (\Exception $e) {
                $results[] = [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}

