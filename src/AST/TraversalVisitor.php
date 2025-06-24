<?php

namespace AST;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use AST\Rules\CWE22_DirectUserInputInSinkRule;
use AST\Rules\CWE22_ConcatenatedPathWithUserInputRule;

class TraversalVisitor extends NodeVisitorAbstract
{
    private array $vulnerabilities = [];
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            new CWE22_DirectUserInputInSinkRule(),
            new CWE22_ConcatenatedPathWithUserInputRule()
        ];
    }

    public function enterNode(Node $node)
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($node)) {
                $this->vulnerabilities[] = new VulnerabilityLocation(
                    $rule->getId(),
                    $rule->getDescription(),
                    $node->getLine(),
                    $node->getStartTokenPos(),
                    $node->getEndTokenPos(),
                    $this->getNodeSource($node)
                );
            }
        }
    }

    private function getNodeSource(Node $node): string
    {
        // Get the file content and extract the relevant lines
        $file = $node->getAttribute('file');
        if (!$file || !file_exists($file)) {
            return '';
        }

        $lines = file($file);
        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        $relevantLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        return implode('', $relevantLines);
    }

    public function getVulnerabilities(): array
    {
        return $this->vulnerabilities;
    }

    public function reset(): void
    {
        $this->vulnerabilities = [];
    }
} 