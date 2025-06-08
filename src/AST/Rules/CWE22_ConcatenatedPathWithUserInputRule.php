<?php

namespace AST\Rules;

use AST\HeuristicRule;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;

class CWE22_ConcatenatedPathWithUserInputRule extends HeuristicRule
{
    public function matches(Node $node): bool
    {
        // Check for concatenation operations
        if ($node instanceof Concat) {
            return $this->checkConcatenation($node);
        }

        // Check for dangerous function calls with concatenated paths
        if ($node instanceof FuncCall && $this->isDangerousFunction($node)) {
            foreach ($node->args as $arg) {
                if ($arg->value instanceof Concat && $this->checkConcatenation($arg->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function checkConcatenation(Concat $node): bool
    {
        // Check if either side of the concatenation is user input
        if ($this->isUserInput($node->left) || $this->isUserInput($node->right)) {
            return true;
        }

        // Recursively check nested concatenations
        if ($node->left instanceof Concat && $this->checkConcatenation($node->left)) {
            return true;
        }
        if ($node->right instanceof Concat && $this->checkConcatenation($node->right)) {
            return true;
        }

        return false;
    }

    public function getId(): string
    {
        return 'CWE-22-CONCAT';
    }

    public function getDescription(): string
    {
        return 'Path concatenation with user input detected, potential path traversal vulnerability';
    }
} 