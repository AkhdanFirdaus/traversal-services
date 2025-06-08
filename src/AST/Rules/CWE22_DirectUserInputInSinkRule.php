<?php

namespace AST\Rules;

use AST\HeuristicRule;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;

class CWE22_DirectUserInputInSinkRule extends HeuristicRule
{
    public function matches(Node $node): bool
    {
        if (!($node instanceof FuncCall)) {
            return false;
        }

        // Check if it's a dangerous function
        if (!$this->isDangerousFunction($node)) {
            return false;
        }

        // Check if any argument is direct user input
        foreach ($node->args as $arg) {
            if ($this->isUserInput($arg->value)) {
                return true;
            }
        }

        return false;
    }

    public function getId(): string
    {
        return 'CWE-22-DIRECT';
    }

    public function getDescription(): string
    {
        return 'Direct use of user input in file system operations without proper sanitization';
    }
} 