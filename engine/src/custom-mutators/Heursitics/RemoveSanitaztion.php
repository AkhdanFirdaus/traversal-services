
<?php

declare(strict_types=1);

namespace App\Mutator\Heuristics;

use PhpParser\Node;
use Infection\Mutator\Util\Mutator;
use Infection\Mutator\Util\MutatorConfig;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;

final class RemoveSanitization extends Mutator
{
    private const SANITIZERS = ['realpath', 'basename'];

    public function mutate(Node $node): iterable
    {
        if (!$node instanceof FuncCall) {
            return;
        }

        if (!($node->name instanceof Node\Name)) {
            return;
        }

        $functionName = strtolower((string) $node->name);

        if (!in_array($functionName, self::SANITIZERS, true)) {
            return;
        }

        if (count($node->args) === 0) {
            return;
        }

        // Replace sanitization function call with its raw input
        yield $node->args[0]->value;
    }

    public function canMutate(Node $node): bool
    {
        return $node instanceof FuncCall
            && $node->name instanceof Node\Name
            && in_array(strtolower((string) $node->name), self::SANITIZERS, true);
    }

    public function getDefinition(): string
    {
        return 'Removes path sanitization functions (e.g., realpath) to simulate insecure file handling.';
    }

    public function getCategory(): string
    {
        return 'Security';
    }
}
