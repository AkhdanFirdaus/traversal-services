<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class OP_FP_ToSimpleConcat implements Mutator
{
    public function canMutate(Node $node): bool
    {
        // Check if the node is a method call with the name "OP_FP"
        return $node instanceof Node\Expr\MethodCall &&
               $node->name instanceof Node\Identifier &&
               $node->name->toString() === 'OP_FP';
    }

    public function mutate(Node $node): array
    {
        // Replace the method call "OP_FP" with a simple string concatenation
        return [
            new Node\Expr\BinaryOp\Concat(
                $node->args[0]->value,
                new Node\Scalar\String_('/' . $node->args[1]->value->value)
            ),
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "OP_FP" method call with a simple string concatenation.
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $destination = $this->OP_FP($absolutePath, $fileName);
                + $destination = $absolutePath . '/' . $fileName;
                DIFF
        );
    }

    public function getName(): string
    {
        return 'OP_FP_ToSimpleConcat';
    }
}