<?php

declare(strict_types=1);

namespace App\AST\Rules;

use App\AST\HeuristicRule;
use App\AST\VulnerabilityLocation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_ as AstString;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\PrettyPrinter;

class GenericPatternRule implements HeuristicRule
{
    private array $patternDefinition;
    private PrettyPrinter\Standard $prettyPrinter;

    /**
     * @param array $patternDefinition A single pattern definition from patterns.json
     * Expected keys: 'cwe', 'name', 'patterns' (array of strings), 'encoding', 'notes' (optional)
     */
    public function __construct(array $patternDefinition)
    {
        $this->patternDefinition = $patternDefinition;
        $this->prettyPrinter = new PrettyPrinter\Standard();
    }

    public function apply(
        Expr $expression,
        string $sinkFunction,
        Node $originalSinkNode,
        array $fileLines
    ): ?VulnerabilityLocation {
        $stringValue = null;

        if ($expression instanceof AstString) {
            $stringValue = $expression->value;
        } elseif ($expression instanceof Encapsed) {
            // For encapsulated strings, concatenate literal parts.
            // This rule primarily looks for literal patterns.
            $tempStringValue = "";
            foreach ($expression->parts as $part) {
                if ($part instanceof EncapsedStringPart) {
                    $tempStringValue .= $part->value;
                } else {
                    // Contains a variable, this rule might not be effective for dynamic parts
                    // unless the pattern itself is part of the literal string segments.
                    // For simplicity, we'll just use the literal parts.
                    // $tempStringValue .= '{VAR}'; // Placeholder for variable part
                }
            }
            $stringValue = $tempStringValue;
        }

        if ($stringValue === null || $stringValue === "") {
            return null;
        }

        // TODO: Handle 'encoding' if specific decoding of $stringValue is needed
        // or if patterns themselves need to be decoded based on $this->patternDefinition['encoding'].
        // For "double", "unicode", "null-byte", the patterns in JSON are often already in the
        // literal form they'd appear in code or as user input.
        // Example: if encoding is "unicode" and pattern is "\u002e", it should match literal "."
        // if php-parser decodes it, or match "\u002e" if it's a literal string in code.

        $matchedPattern = null;
        foreach ($this->patternDefinition['patterns'] as $patternToSearch) {
            if (str_contains((string)$stringValue, $patternToSearch)) {
                $matchedPattern = $patternToSearch;
                break;
            }
        }

        if ($matchedPattern !== null) {
            $inputDescription = $this->prettyPrinter->prettyPrintExpr($expression);
            // Could refine inputDescription to highlight the $matchedPattern
            // $inputDescription = "String literal containing pattern '{$matchedPattern}': " . $this->prettyPrinter->prettyPrintExpr($expression);

            $snippet = $this->getCodeSnippet($originalSinkNode, $fileLines);

            return new VulnerabilityLocation(
                '', // File path set by analyzer
                $originalSinkNode->getStartLine(),
                $originalSinkNode->getEndLine(),
                $snippet,
                $inputDescription,
                $sinkFunction,
                $this->getCweId(),
                $this->getRuleName(),
                $this->getRuleDescription() . " (Matched pattern: '{$matchedPattern}')"
            );
        }

        return null;
    }

    private function getCodeSnippet(Node $node, array $fileLines, int $contextLines = 2): string
    {
        $startLine = max(1, $node->getStartLine() - $contextLines);
        $endLine = min(count($fileLines), $node->getEndLine() + $contextLines);
        $snippet = [];
        for ($i = $startLine - 1; $i < $endLine; $i++) {
            if (isset($fileLines[$i])) {
                $prefix = ($i + 1 >= $node->getStartLine() && $i + 1 <= $node->getEndLine()) ? ">> " : "   ";
                $snippet[] = $prefix . str_pad((string)($i + 1), 4, " ", STR_PAD_LEFT) . ": " . rtrim($fileLines[$i]);
            }
        }
        return implode("\n", $snippet);
    }

    public function getCweId(): string
    {
        return $this->patternDefinition['cwe'] ?? 'N/A';
    }

    public function getRuleName(): string
    {
        return $this->patternDefinition['name'] ?? 'Unnamed Generic Pattern Rule';
    }

    public function getRuleDescription(): string
    {
        $baseDesc = $this->patternDefinition['notes'] ?? $this->getRuleName();
        if (empty(trim($baseDesc))) {
            $baseDesc = "Detects a hardcoded string pattern associated with " . $this->getRuleName();
        }
        return $baseDesc;
    }
}
