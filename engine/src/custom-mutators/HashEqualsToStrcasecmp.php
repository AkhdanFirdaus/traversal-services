<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class HashEqualsToStrcasecmp implements Mutator
{
    public function canMutate(Node $node): bool
    {
        // Check if the node is a function call with the name "hash_equals"
        return $node instanceof Node\Expr\FuncCall &&
               $node->name instanceof Node\Name &&
               $node->name->toString() === 'hash_equals';
    }

    public function mutate(Node $node): array
    {
        // Replace the function call "hash_equals" with "strcasecmp"
        return [
            new Node\Expr\FuncCall(
                new Node\Name('strcasecmp'),
                $node->args,
                $node->getAttributes()
            ),
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "hash_equals(" with "strcasecmp(".
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $result = hash_equals($hash1, $hash2);
                + $result = strcasecmp($hash1, $hash2);
                DIFF
        );
    }

    public function getName(): string
    {
        return 'HashEqualsToStrcasecmp';
    }
}