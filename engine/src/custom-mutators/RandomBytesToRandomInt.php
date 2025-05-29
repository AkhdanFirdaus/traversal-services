<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class RandomBytesToRandomInt implements Mutator
{
    public function canMutate(Node $node): bool
    {
        return $node instanceof Node\Expr\FuncCall &&
               $node->name instanceof Node\Name &&
               $node->name->toString() === 'random_bytes';
    }

    public function mutate(Node $node): array
    {
        return [
            new Node\Expr\FuncCall(
                new Node\Name('random_int'),
                [
                    new Node\Arg(new Node\Scalar\LNumber(0)), // $min = 0
                    $node->args[0], // $max = $originalArgs[0]
                ],
                $node->getAttributes()
            ),
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "random_bytes(" with "random_int(".
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $bytes = random_bytes(32);
                + $bytes = random_int(0, 32);
                DIFF
        );
    }

    public function getName(): string
    {
        return 'RandomBytesToRandomInt';
    }
}