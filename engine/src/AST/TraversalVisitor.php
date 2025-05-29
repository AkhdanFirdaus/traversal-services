<?php

declare(strict_types=1);

namespace App\AST;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;

class TraversalVisitor extends NodeVisitorAbstract
{
    private string $currentFilePath;
    private array $fileContentLines; // Array of lines from the current file

    /** @var HeuristicRule[] */
    private array $rules;
    private array $vulnerabilities = [];

    // Sink functions and the argument indices that typically hold paths
    // This could be externalized to a configuration if it grows too large
    private const SINK_FUNCTION_ARG_INDICES = [
        // File inclusion
        'include' => [0], 'include_once' => [0], 'require' => [0], 'require_once' => [0],
        // File/Stream operations (path is usually the first argument)
        'file_get_contents' => [0], 'file_put_contents' => [0], // arg 0 for path, arg 1 for content
        'fopen' => [0], 'readfile' => [0], 'unlink' => [0],
        'copy' => [0, 1], // arg 0 (source), arg 1 (destination)
        'rename' => [0, 1], // arg 0 (old), arg 1 (new)
        'file_exists' => [0], 'is_file' => [0], 'is_dir' => [0], 'is_link' => [0],
        'stat' => [0], 'lstat' => [0], 'filesize' => [0], 'filetype' => [0],
        'md5_file' => [0], 'sha1_file' => [0],
        'parse_ini_file' => [0], 'touch' => [0],
        'chmod' => [0], 'chown' => [0], 'chgrp' => [0],
        'move_uploaded_file' => [1], // Destination path is the second argument (index 1)
        // Directory operations
        'glob' => [0], 'mkdir' => [0], 'rmdir' => [0], 'opendir' => [0], 'scandir' => [0],
        'dirname' => [0], // Can reveal path structure if output is used insecurely
        // Link operations
        'readlink' => [0], 'symlink' => [1], 'link' => [1], // target is arg 1 for symlink/link
        // Other potentially sensitive functions if path is controlled
        'header' => [0], // e.g., Location: redirecting to a user-controlled path/URL
    ];

    /**
     * @param HeuristicRule[] $rules Array of heuristic rules to apply.
     * @param string $currentFilePath Path of the file being analyzed.
     * @param string $fileContent Content of the file being analyzed.
     */
    public function __construct(array $rules, string $currentFilePath, string $fileContent)
    {
        $this->rules = $rules;
        $this->currentFilePath = $currentFilePath;
        $this->fileContentLines = explode("\n", $fileContent);
    }

    public function enterNode(Node $node): void
    {
        $sinkFunctionName = null;
        $expressionsToAnalyze = []; // Can be multiple for functions like copy(), rename()

        if ($node instanceof Include_) {
            $sinkFunctionName = $this->getIncludeTypeString($node);
            $expressionsToAnalyze[] = ['expr' => $node->expr, 'index' => 0];
        } elseif ($node instanceof FuncCall && $node->name instanceof Name) {
            $functionNameLower = strtolower($node->name->toString());
            if (array_key_exists($functionNameLower, self::SINK_FUNCTION_ARG_INDICES)) {
                $sinkFunctionName = $functionNameLower;
                $argIndices = self::SINK_FUNCTION_ARG_INDICES[$functionNameLower];
                foreach ($argIndices as $idx) {
                    if (isset($node->args[$idx]) && $node->args[$idx]->value instanceof Expr) {
                        $expressionsToAnalyze[] = ['expr' => $node->args[$idx]->value, 'index' => $idx];
                    }
                }
            }
        }

        if ($sinkFunctionName && !empty($expressionsToAnalyze)) {
            foreach ($expressionsToAnalyze as $item) {
                /** @var Expr $expression */
                $expression = $item['expr'];
                // $argIndex = $item['index']; // Could be useful for context in rules

                foreach ($this->rules as $rule) {
                    $vulnerability = $rule->apply($expression, $sinkFunctionName, $node, $this->fileContentLines);
                    if ($vulnerability instanceof VulnerabilityLocation) {
                        // Set the file path here as the visitor knows it
                        $vulnerability->filePath = $this->currentFilePath;
                        $this->vulnerabilities[] = $vulnerability;
                        // Optionally, break after the first rule match for a given expression,
                        // or collect all rule violations. Collecting all is generally better.
                    }
                }
            }
        }
    }

    private function getIncludeTypeString(Include_ $node): string
    {
        return match ($node->type) {
            Include_::TYPE_INCLUDE => 'include',
            Include_::TYPE_INCLUDE_ONCE => 'include_once',
            Include_::TYPE_REQUIRE => 'require',
            Include_::TYPE_REQUIRE_ONCE => 'require_once',
            default => 'unknown_include_type', // Should not happen
        };
    }

    /**
     * @return VulnerabilityLocation[]
     */
    public function getVulnerabilities(): array
    {
        // Deduplicate vulnerabilities if necessary (e.g., same line, same rule, same input desc)
        // For now, returning as collected. Deduplication can be complex.
        return $this->vulnerabilities;
    }
}
