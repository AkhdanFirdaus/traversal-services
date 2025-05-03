<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class OP_PP_ToEmptyString implements Mutator
{
    public function canMutate(Node $node): bool
    {
        // Check if the node is a method call with the name "OP_PP"
        return $node instanceof Node\Expr\MethodCall &&
               $node->name instanceof Node\Identifier &&
               $node->name->toString() === 'OP_PP';
    }

    public function mutate(Node $node): array
    {
        // Replace the method call "OP_PP" with an empty string
        return [
            new Node\Scalar\String_(''),
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "OP_PP" method call with an empty string.
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $tmpPath = $this->OP_PP($file);
                + $tmpPath = '';
                DIFF
        );
    }

    public function getName(): string
    {
        return 'OP_PP_ToEmptyString';
    }
}