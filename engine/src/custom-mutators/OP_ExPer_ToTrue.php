<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class OP_ExPer_ToTrue implements Mutator
{
    public function canMutate(Node $node): bool
    {
        // Check if the node is a method call with the name "OP_ExPer"
        return $node instanceof Node\Expr\MethodCall &&
               $node->name instanceof Node\Identifier &&
               $node->name->toString() === 'OP_ExPer';
    }

    public function mutate(Node $node): array
    {
        // Replace the method call "OP_ExPer" with `true`
        return [
            new Node\Expr\ConstFetch(new Node\Name('true')),
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "OP_ExPer" method call with `true`.
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - if (!$this->OP_ExPer($absolutePath)) {
                + if (true) {
                DIFF
        );
    }

    public function getName(): string
    {
        return 'OP_ExPer_ToTrue';
    }
}