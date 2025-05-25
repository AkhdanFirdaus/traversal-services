<?php

declare(strict_types=1);

namespace App\AST\Rules;

use App\AST\HeuristicRule;
use App\AST\VulnerabilityLocation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_ as AstString;
use PhpParser\PrettyPrinter;

class CWE22_ConcatenatedPathWithUserInputRule implements HeuristicRule
{
    private const CWE_ID = "CWE-22"; // Also CWE-73
    private const RULE_NAME = "Concatenated Path with User Input";
    private const RULE_DESCRIPTION = "Detects file paths constructed by concatenating strings where at least one part appears to be direct user input (e.g., \$_GET, \$_POST), and the final path is used in a file system sink function without apparent sanitization.";

    private const USER_INPUT_SOURCES_SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_FILES'];
    private const SANITIZATION_FUNCTIONS = ['basename', 'realpath'];

    private PrettyPrinter\Standard $prettyPrinter;
    private CWE22_DirectUserInputInSinkRule $directInputRuleChecker; // Reuse for checking parts

    public function __construct()
    {
        $this->prettyPrinter = new PrettyPrinter\Standard();
        $this->directInputRuleChecker = new CWE22_DirectUserInputInSinkRule(); // Helper
    }

    public function apply(
        Expr $expression,
        string $sinkFunction,
        Node $originalSinkNode,
        array $fileLines
    ): ?VulnerabilityLocation {
        if ($expression instanceof Expr\BinaryOp\Concat) {
            if ($this->concatenationContainsUserInput($expression) && !$this->isEntireExpressionSanitized($expression)) {
                $inputDescription = $this->prettyPrinter->prettyPrintExpr($expression);
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
                    $this->getRuleDescription()
                );
            }
        }
        return null;
    }

    private function concatenationContainsUserInput(Expr\BinaryOp\Concat $concatExpr): bool
    {
        $parts = [];
        $this->flattenConcatenation($concatExpr, $parts);

        foreach ($parts as $part) {
            // Use the logic from DirectUserInputInSinkRule to check if a part is user input
            if ($this->directInputRuleChecker->apply($part, '', new Node\Stmt\Nop(), []) !== null) {
                 // If directInputRuleChecker->apply returns a VulnerabilityLocation, it means it's user input.
                 // We pass dummy sink, node, and fileLines as they are not strictly needed for its internal check here.
                return true;
            }
        }
        return false;
    }

    private function flattenConcatenation(Expr $expr, array &$parts): void
    {
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $this->flattenConcatenation($expr->left, $parts);
            $this->flattenConcatenation($expr->right, $parts);
        } else {
            $parts[] = $expr;
        }
    }

    private function isEntireExpressionSanitized(Expr $expression): bool
    {
        // This checks if the *entire* concatenation result is wrapped by a sanitization function.
        // e.g., include(basename("uploads/" . $_GET['file']))
        // This is a very basic check.
        // A more robust solution would require data flow analysis to see if the
        // user input part was sanitized *before* concatenation.

        // This rule does not currently implement this check effectively beyond what
        // DirectUserInputInSinkRule would check if the expression itself was a FuncCall.
        // We assume if any part is user input and the whole thing isn't wrapped, it's risky.
        return false; // Assume not sanitized for simplicity unless the whole thing is a sanitizing call
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
        return self::CWE_ID;
    }

    public function getRuleName(): string
    {
        return self::RULE_NAME;
    }

    public function getRuleDescription(): string
    {
        return self::RULE_DESCRIPTION;
    }
}
