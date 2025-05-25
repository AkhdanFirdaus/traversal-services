<?php

declare(strict_types=1);

namespace App\Mutator;

use Infection\Mutator\Definition;
use Infection\Mutator\Mutator;
use Infection\Mutator\MutatorCategory;
use PhpParser\Node;

class OP_AP_ToOriginalPath implements Mutator
{
    public function canMutate(Node $node): bool
    {
        // Check if the node is a method call with the name "OP_AP"
        return $node instanceof Node\Expr\MethodCall &&
               $node->name instanceof Node\Identifier &&
               $node->name->toString() === 'OP_AP';
    }

    public function mutate(Node $node): array
    {
        // Replace the method call "OP_AP" with the original path argument
        // $node->args[0] adalah argumen pertama yang diberikan ke metode OP_AP
        return [
            $node->args[0]->value, // Mengembalikan nilai argumen pertama
        ];
    }

    public static function getDefinition(): Definition
    {
        return new Definition(
            <<<'TXT'
                Replaces "OP_AP" method call with the original path argument.
                TXT,
            MutatorCategory::ORTHOGONAL_REPLACEMENT,
            null,
            <<<'DIFF'
                - $absolutePath = $this->OP_AP($uploadPath);
                + $absolutePath = $uploadPath;
                DIFF
        );
    }

    public function getName(): string
    {
        return 'OP_AP_ToOriginalPath';
    }
}