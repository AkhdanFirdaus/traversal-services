<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class HmacAlgoToBlake2b implements Mutator
{
    public function canMutate(Node $node): bool
    {
        // Check if the node is a PropertyFetch or Assign
        if ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\Assign) {
            // If the node is a PropertyFetch
            if ($node instanceof Node\Expr\PropertyFetch) {
                // Check if we are accessing a property of the current object ($this)
                if ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                    // Check if the property name is 'hashAlgo'
                    if ($node->name instanceof Node\Identifier && $node->name->name === 'hashAlgo') {
                        return true;
                    }
                }
            }

            // If the node is an Assign
            if ($node instanceof Node\Expr\Assign) {
                // Check if the right-hand side expression is 'sha256'
                if ($node->expr instanceof Node\Scalar\String_ && $node->expr->value === 'sha256') {
                    return true;
                }
            }
        }

        return false;
    }

    public function mutate(Node $node): array
    {
        // If the node is a PropertyFetch
        if ($node instanceof Node\Expr\PropertyFetch) {
            // Check if this is a property fetch with the value 'sha256'
            if ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                // Replace 'sha256' with 'sha1'
                return [
                    new Node\Expr\Assign(
                        $node, // The property being assigned
                        new Node\Scalar\String_('blake2b') // New value
                    ),
                ];
            }
        }

        // If the node is an Assign
        if ($node instanceof Node\Expr\Assign) {
            if ($node->expr instanceof Node\Scalar\String_ && $node->expr->value === 'sha256') {
                // Replace 'sha256' with 'sha1'
                return [
                    new Node\Expr\Assign(
                        $node->var, // The variable being assigned
                        new Node\Scalar\String_('blake2b'), // New value
                        $node->getAttributes() // Preserve attributes
                    ),
                ];
            }
        }

        return [$node]; // Return the mutated node
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "$hashAlgo = 'sha256'" with "$hashAlgo = 'blake2b'".
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $hashAlgo = 'sha256';
                + $hashAlgo = 'blake2b';
                DIFF
        );
    }

    public function getName(): string
    {
        return 'HmacAlgoToBlake2b';
    }
}