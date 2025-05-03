<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class RandomBytesToOpenSslRandomPseudoBytes implements Mutator
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
        // Ganti function call "random_bytes" dengan "openssl_random_pseudo_bytes"
        return [
            new Node\Expr\FuncCall(
                new Node\Name('openssl_random_pseudo_bytes'),
                $node->args,
                $node->getAttributes()
            ),
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "random_bytes(" with "openssl_random_pseudo_bytes(".
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $bytes = random_bytes(32);
                + $bytes = openssl_random_pseudo_bytes(32);
                DIFF
        );
    }

    public function getName(): string
    {
        return 'RandomBytesToOpenSslRandomPseudoBytes';
    }
}