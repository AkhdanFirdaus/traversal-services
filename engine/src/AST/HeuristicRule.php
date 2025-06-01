<?php

namespace AST;

use PhpParser\Node;

abstract class HeuristicRule
{
    abstract public function matches(Node $node): bool;
    abstract public function getId(): string;
    abstract public function getDescription(): string;

    protected function isUserInput(Node $node): bool
    {
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            if ($node->var instanceof Node\Expr\Variable) {
                $varName = $node->var->name;
                return in_array($varName, ['_GET', '_POST', '_REQUEST', '_FILES']);
            }
        }
        return false;
    }

    protected function isDangerousFunction(Node\Expr\FuncCall $node): bool
    {
        if ($node->name instanceof Node\Name) {
            $functionName = $node->name->toString();
            return in_array($functionName, [
                'file_get_contents',
                'fopen',
                'file',
                'readfile',
                'unlink',
                'rmdir',
                'mkdir',
                'rename',
                'copy',
                'include',
                'include_once',
                'require',
                'require_once'
            ]);
        }
        return false;
    }

    protected function containsTraversalPattern(string $value): bool
    {
        $patterns = [
            '/\.\.[\\\\\\/]/',
            '/\\/\.\./',
            '/\.\.%2f/i',
            '/%2f\.\./i',
            '/\.\.\//',
            '/\/\.\./',
            '/\.\./',
            '/\.\.\\\\/',
            '/\.\.$/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        return false;
    }
} 