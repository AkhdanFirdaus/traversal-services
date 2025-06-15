<?php

namespace Pipeline;

use Gemini;
use Gemini\Client;
use Gemini\Data\Content;
use Gemini\Data\FunctionCall;
use Gemini\Data\FunctionDeclaration;
use Gemini\Data\FunctionResponse;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Part;
use Gemini\Data\Schema;
use Gemini\Data\Tool;
use Gemini\Enums\DataType;
use Gemini\Enums\Role;
use Utils\FileHelper;
use Utils\JsonCleaner;
use Utils\Logger;
use Utils\PromptBuilder;

class AiTestGenerator
{
    private Client $client;

    public function __construct(private string $projectDir, private string $outputDir, private Logger $logger) {
        $this->client = Gemini::client($_ENV['GEMINI_API_KEY']);
    }

     private function getGenerationConfig(): GenerationConfig {
        return new GenerationConfig(temperature: 0.1);
    }

    private function getFileContentTool(): Tool {
        return new Tool(
            functionDeclarations: [
                new FunctionDeclaration(
                    name: 'get_file_content',
                    description: "Retrieves the full content of a specific file from the project. Use this to read project structure, reports, or source code.",
                    parameters: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'file_path' => new Schema(
                                type: DataType::STRING,
                                description: 'The absolute path to the file on disk.'
                            ),
                        ],
                        required: ['file_path']
                    ),
                )
            ]
        );
    }

    /**
     * Executes the file read operation requested by the model.
     */
    private function executeGetFileContent(string $filePath): string
    {
        // Simple security check to ensure we stay within the project
        if (strpos(realpath($filePath), realpath($this->projectDir)) !== 0 && $filePath !== '/app/config/patterns.json') {
             return "Error: Access denied. Cannot read files outside of the project directory.";
        }
        
        $this->logger->info('Function Call: Reading file', ['path' => $filePath]);

        try {
            $content = FileHelper::readFile($filePath);
            return "--- Content of " . basename($filePath) . " ---\n{$content}\n--- End Content ---";
        } catch (\Throwable $th) {
            return "Error reading file {$filePath}: {$th->getMessage()}";
        }
    }
    
    private function handleFunctionCall(FunctionCall $functionCall): Content
    {
        if ($functionCall->name === 'get_file_content') {
            $filePath = $functionCall->args['file_path'];
            $result = $this->executeGetFileContent($filePath);

            return new Content(
                parts: [
                    new Part(
                        functionResponse: new FunctionResponse(
                            name: 'get_file_content',
                            response: ['content' => $result]
                        )
                    )
                ],
                role: Role::MODEL
            );
        }

        return new Content(
            parts: [ new Part( functionResponse: new FunctionResponse(name: $functionCall->name, response: ['error' => 'Unknown function called.']) ) ],
            role: Role::MODEL
        );
    }

    /**
     * PHASE 1: Analyzes the system using function calling to identify vulnerable files.
     */
    public function analyzeSystems(string $projectStructure, array $phpUnitReport, string $mutationReport, $iterate): array {
        $this->logger->info('AIGenerator: [Phase 1] Analyzing Systems using Function Calling...');

        // Create a list of available file paths for the model to choose from.
        $availableFilesContext = "The following files are available for analysis. Use the `get_file_content` tool to read them.\n\n";
        $availableFilesContext .= "- Project Structure: $projectStructure\n";
        $availableFilesContext .= "- Path Traversal Patterns: /app/config/patterns.json\n";
        $availableFilesContext .= "- Mutation Report: $mutationReport\n";
        
        foreach ($phpUnitReport as $reportPath) {
            $availableFilesContext .= "- PHPUnit Report: $reportPath\n";
        }

        $chat = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig($this->getGenerationConfig())
            ->withTool($this->getFileContentTool())
            ->startChat(history: [
                Content::parse(part: $availableFilesContext, role: Role::USER),
            ]);

        $response = $chat->sendMessage(PromptBuilder::analyzeSystem());

        while ($response->parts()[0]->functionCall !== null) {
            $functionCall = $response->parts()[0]->functionCall;
            $this->logger->info('AIGenerator: [Phase 1] Model requested function call.', ['name' => $functionCall->name, 'args' => $functionCall->args]);
            $functionResponseContent = $this->handleFunctionCall($functionCall);
            $response = $chat->sendMessage($functionResponseContent);
        }

        $this->logger->info('AIGenerator: [Phase 1] Received final analysis from the model.');
        
        $analysisJson = $response->text();
        $analysisJson = JsonCleaner::parse($analysisJson);
        
        $resultsPath = $this->outputDir . DIRECTORY_SEPARATOR . "analyze-results-$iterate.json";
        file_put_contents($resultsPath, json_encode($analysisJson, JSON_PRETTY_PRINT));

        return $analysisJson;
    }
    
    /**
     * PHASE 2: Generates the test case based on the analysis.
     */
    public function generateTestCase(array $analyzerResults, $iterate): array {
        $this->logger->info('AIGenerator: [Phase 2] Generating Test Case with multi-turn conversation');

        $analyzerResultsJson = json_encode($analyzerResults, JSON_PRETTY_PRINT);
        
        $chat = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig($this->getGenerationConfig())
            ->withTool($this->getFileContentTool())
            ->startChat();
        
        $finalPrompt = PromptBuilder::generatorPrompt() . "\n\n# Analysis to Address:\n" . $analyzerResultsJson;;
        $response = $chat->sendMessage($finalPrompt);

        while ($response->parts()[0]->functionCall !== null) {
            $functionCall = $response->parts()[0]->functionCall;
            $this->logger->info('AIGenerator: [Phase 2] Model requested function call.', ['name' => $functionCall->name, 'args' => $functionCall->args]);
            $functionResponseContent = $this->handleFunctionCall($functionCall);
            $response = $chat->sendMessage($functionResponseContent);
        }

        $this->logger->info('AIGenerator: [Phase 2] Received final test case from the model.');

        $finalResponseJson = $response->text();
        $finalResponseJson = JsonCleaner::parse($finalResponseJson);

        $resultsPath = $this->outputDir . DIRECTORY_SEPARATOR . "generated-results-$iterate.json";
        file_put_contents($resultsPath, json_encode($finalResponseJson, JSON_PRETTY_PRINT));

        return $finalResponseJson;
    }

    public function rewriteCode(array $generated) : string {
        try {
            $this->logger->info('AIGenerator: Rewriting Code with New Test Case');

            if (!isset($generated['file_path']) || !isset($generated['code'])) {
                 throw new \Exception("Generated JSON is invalid. Missing 'file_path' or 'code' key.");
            }

            $dest = $generated['file_path'];
            file_put_contents(
                $this->projectDir . DIRECTORY_SEPARATOR . $dest,
                $generated['code']
            );

            return $this->projectDir . DIRECTORY_SEPARATOR . 'tests';

        } catch (\Throwable $th) {
            $this->logger->error('Failed to rewrite file', [
                'error' => $th->getMessage(),
            ]);
            throw $th;
        }
    }
} 