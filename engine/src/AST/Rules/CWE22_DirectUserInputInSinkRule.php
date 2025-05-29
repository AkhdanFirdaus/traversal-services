<?php

declare(strict_types=1);

namespace App\AST\Rules;

use App\AST\HeuristicRule;
use App\AST\VulnerabilityLocation;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_ as AstString;
use PhpParser\PrettyPrinter;

class CWE22_DirectUserInputInSinkRule implements HeuristicRule
{
    private const CWE_ID = "CWE-22"; // Also covers CWE-73 (External Control of File Name or Path)
    private const RULE_NAME = "Direct User Input in Sink";
    private const RULE_DESCRIPTION = "Detects direct usage of superglobals (e.g., \$_GET, \$_POST, \$_COOKIE, \$_FILES) as arguments to file system sink functions without apparent sanitization. This can lead to Path Traversal vulnerabilities.";

    // Common superglobals that are sources of user input
    private const USER_INPUT_SOURCES_SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_FILES'];

    // Basic sanitization functions. A more robust check would involve taint analysis.
    private const SANITIZATION_FUNCTIONS = ['basename', 'realpath']; // realpath can be tricky

    private PrettyPrinter\Standard $prettyPrinter;

    public function __construct()
    {
        $this->prettyPrinter = new PrettyPrinter\Standard();
    }

    public function apply(
        Expr $expression,
        string $sinkFunction,
        Node $originalSinkNode,
        array $fileLines
    ): ?VulnerabilityLocation {
        if ($this->isDirectUserInput($expression) && !$this->isExpressionSanitized($expression, $originalSinkNode)) {
            $inputDescription = $this->prettyPrinter->prettyPrintExpr($expression);
            $snippet = $this->getCodeSnippet($originalSinkNode, $fileLines);

            return new VulnerabilityLocation(
                '', // File path will be set by the visitor/analyzer
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
        return null;
    }

    private function isDirectUserInput(Expr $expression): bool
    {
        if ($expression instanceof Expr\ArrayDimFetch) {
            // Check for $_GET['key'], $_POST['key'], etc.
            if ($expression->var instanceof Expr\Variable &&
                in_array((string)$expression->var->name, self::USER_INPUT_SOURCES_SUPERGLOBALS)) {
                return true;
            }
            // Specifically for $_FILES['upload_field']['name']
            if ($expression->dim instanceof AstString && $expression->dim->value === 'name' &&
                $expression->var instanceof Expr\ArrayDimFetch &&
                $expression->var->var instanceof Expr\Variable &&
                (string)$expression->var->var->name === '_FILES'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Basic check to see if the expression is immediately wrapped by a sanitization function.
     * This is a very naive check. Proper taint analysis is needed for accuracy.
     */
    private function isExpressionSanitized(Expr $expression, Node $originalSinkNode): bool
    {
        // Check if the argument to the sink function is a FuncCall to a sanitization function
        // e.g., include(basename($_GET['file']))
        if ($expression instanceof Expr\FuncCall && $expression->name instanceof Node\Name) {
            $funcName = strtolower($expression->name->toString());
            if (in_array($funcName, self::SANITIZATION_FUNCTIONS)) {
                // Further check: is the argument of the sanitization function the actual user input?
                // This is to avoid flagging include(basename($already_safe_var))
                if (isset($expression->args[0]) && $expression->args[0]->value instanceof Expr) {
                    if ($this->isDirectUserInput($expression->args[0]->value)) {
                        return true; // Sanitized at point of use
                    }
                }
            }
        }

        // A more complex check would be to see if the variable holding the user input
        // was sanitized on a previous line. This requires data-flow/taint analysis.
        // For now, this rule is simple and may produce false positives if sanitization
        // happens indirectly.
        return false;
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
