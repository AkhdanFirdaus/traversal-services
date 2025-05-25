<?php

declare(strict_types=1);

namespace App\AST;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Interface for all heuristic rules used in AST analysis.
 */
interface HeuristicRule
{
    /**
     * Checks if this rule applies to the given AST expression within a sink function.
     *
     * @param Expr $expression The expression being analyzed (e.g., an argument to a sink function).
     * @param string $sinkFunction The name of the sink function (e.g., "include", "file_get_contents").
     * @param Node $originalSinkNode The original AST node representing the sink (e.g., FuncCall, Include_).
     * This provides context like line numbers.
     * @param array $fileLines Array of lines from the source file, for snippet extraction.
     * @return VulnerabilityLocation|null A VulnerabilityLocation object if the rule applies and detects
     * a potential vulnerability, null otherwise.
     */
    public function apply(
        Expr $expression,
        string $sinkFunction,
        Node $originalSinkNode,
        array $fileLines
    ): ?VulnerabilityLocation;

    /**
     * Gets the CWE ID associated with this rule (e.g., "CWE-22").
     * @return string
     */
    public function getCweId(): string;

    /**
     * Gets a human-readable name for this rule.
     * @return string
     */
    public function getRuleName(): string;

    /**
     * Gets a detailed description of what this rule checks for.
     * @return string
     */
    public function getRuleDescription(): string;
}
