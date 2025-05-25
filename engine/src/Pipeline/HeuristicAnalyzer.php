<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\AST\TraversalVisitor;
use App\AST\VulnerabilityLocation;
use App\AST\Rules\CWE22_DirectUserInputInSinkRule;
use App\AST\Rules\CWE22_ConcatenatedPathWithUserInputRule;
use App\AST\Rules\GenericPatternRule;
use App\Utils\FileHelper;
use App\Utils\Logger;
use App\Utils\PatternLoader;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\Error as ParserError;
use Throwable;

class HeuristicAnalyzer
{
    private \PhpParser\Parser $parser;
    private array $rules = [];
    private ?Logger $logger;
    private PatternLoader $patternLoader;

    public function __construct(?Logger $logger = null, ?PatternLoader $patternLoader = null, string $patternsJsonPath = '')
    {
        $this->logger = $logger;
        $this->patternLoader = $patternLoader ?? new PatternLoader($this->logger);

        $parserFactory = new ParserFactory();
        // Use LATEST for broader compatibility, or specify PHP8 if your target is strictly 8+
        $this->parser = $parserFactory->createForNewestSupportedVersion();

        $this->initializeRules($patternsJsonPath);
    }

    private function initializeRules(string $patternsJsonPath): void
    {
        // Add built-in, code-defined rules
        $this->rules[] = new CWE22_DirectUserInputInSinkRule();
        $this->rules[] = new CWE22_ConcatenatedPathWithUserInputRule();

        // Load rules from patterns.json if path is provided
        if (!empty($patternsJsonPath)) {
            $loadedPatternDefinitions = $this->patternLoader->loadPatterns($patternsJsonPath);
            foreach ($loadedPatternDefinitions as $patternDef) {
                if (isset($patternDef['cwe'], $patternDef['name'], $patternDef['patterns']) && is_array($patternDef['patterns'])) {
                    $this->rules[] = new GenericPatternRule($patternDef);
                    $this->logger?->debug("Initialized GenericPatternRule: {ruleName}", ['ruleName' => $patternDef['name']]);
                } else {
                    $this->logger?->warning("Skipped invalid pattern definition: {patternDefinition}", ['patternDefinition' => json_encode($patternDef)]);
                }
            }
        } else {
            $this->logger?->info("No patterns.json path provided to HeuristicAnalyzer, skipping generic pattern rules.");
        }
        $this->logger?->info("HeuristicAnalyzer initialized with {ruleCount} rules.", ['ruleCount' => count($this->rules)]);
    }

    /**
     * Analyzes a single PHP file for vulnerabilities.
     *
     * @param string $filePath Path to the PHP file.
     * @return VulnerabilityLocation[] An array of found vulnerabilities.
     */
    public function analyzeFile(string $filePath): array
    {
        $this->logger?->info("Starting heuristic analysis for file: {filePath}", ['filePath' => $filePath]);
        $code = FileHelper::readFile($filePath, $this->logger);
        if ($code === null) {
            return []; // Error already logged by FileHelper or here
        }
        return $this->analyzeCode($code, $filePath);
    }

    /**
     * Analyzes a string of PHP code for vulnerabilities.
     *
     * @param string $code The PHP code string.
     * @param string $pseudoFilePath A path to associate with the code (for reporting).
     * @return VulnerabilityLocation[] An array of found vulnerabilities.
     */
    public function analyzeCode(string $code, string $pseudoFilePath = 'virtual.php'): array
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (ParserError $e) {
            $this->logger?->error("Parse error in {filePath}: {errorMessage}", ['filePath' => $pseudoFilePath, 'errorMessage' => $e->getMessage()]);
            return [];
        } catch (Throwable $e) {
            $this->logger?->error("Unexpected error during parsing of {filePath}: {errorMessage}", ['filePath' => $pseudoFilePath, 'errorMessage' => $e->getMessage()]);
            return [];
        }


        if ($ast === null) { // Should be caught by try-catch but as a safeguard
            $this->logger?->error("AST is null after parsing {filePath}, cannot analyze.", ['filePath' => $pseudoFilePath]);
            return [];
        }

        $traverser = new NodeTraverser();
        // Pass the file path and content to the visitor for context and snippet generation
        $visitor = new TraversalVisitor($this->rules, $pseudoFilePath, $code);
        $traverser->addVisitor($visitor);

        try {
            $traverser->traverse($ast);
        } catch (Throwable $e) {
            $this->logger?->error("Error during AST traversal in {filePath}: {errorMessage}", ['filePath' => $pseudoFilePath, 'errorMessage' => $e->getMessage()]);
            // Optionally, return partial results if visitor collected some before erroring
            // return $visitor->getVulnerabilities();
            return [];
        }

        $vulnerabilities = $visitor->getVulnerabilities();
        $this->logger?->info("Heuristic analysis of {filePath} found {count} potential vulnerabilities.", [
            'filePath' => $pseudoFilePath,
            'count' => count($vulnerabilities)
        ]);
        return $vulnerabilities;
    }

    /**
     * Analyzes all PHP files in a given directory recursively.
     *
     * @param string $directoryPath Path to the directory.
     * @return array An associative array where keys are file paths and values are arrays of VulnerabilityLocation.
     */
    public function analyzeDirectory(string $directoryPath): array
    {
        $this->logger?->info("Starting heuristic analysis for directory: {directoryPath}", ['directoryPath' => $directoryPath]);
        $allVulnerabilities = [];
        if (!is_dir($directoryPath)) {
            $this->logger?->error("Directory not found: {directoryPath}", ['directoryPath' => $directoryPath]);
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directoryPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $filePath = $file->getRealPath();
                if ($filePath) {
                    $fileVulnerabilities = $this->analyzeFile($filePath);
                    if (!empty($fileVulnerabilities)) {
                        $allVulnerabilities[$filePath] = $fileVulnerabilities;
                    }
                }
            }
        }
        $this->logger?->info("Heuristic analysis of directory {directoryPath} completed.", ['directoryPath' => $directoryPath]);
        return $allVulnerabilities;
    }
}
