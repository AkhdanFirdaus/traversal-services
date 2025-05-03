<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class RandomBytesToMcryptCreateIv implements Mutator
{
    public function canMutate(Node $node): bool
    {
        // Periksa apakah node adalah function call dengan nama "random_bytes"
        return $node instanceof Node\Expr\FuncCall &&
               $node->name instanceof Node\Name &&
               $node->name->toString() === 'random_bytes';
    }

    public function mutate(Node $node): array
    {
        // Ganti function call "random_bytes" dengan "mcrypt_create_iv"
        return [
            new Node\Expr\FuncCall(
                new Node\Name('mcrypt_create_iv'),
                [
                    $node->args[0], // $size = $originalArgs[0]
                    new Node\Arg(new Node\Scalar\LNumber(0)), // $source = 0 (MCRYPT_DEV_URANDOM)
                ],
                $node->getAttributes()
            ),
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "random_bytes(" with "mcrypt_create_iv(".
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $bytes = random_bytes(32);
                + $bytes = mcrypt_create_iv(32, 0); // 0 adalah nilai untuk MCRYPT_DEV_URANDOM
                DIFF
        );
    }

    public function getName(): string
    {
        return 'RandomBytesToMcryptCreateIv';
    }
}