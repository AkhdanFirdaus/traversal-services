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
use Gemini\Resources\ChatSession;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\JsonCleaner;
use Utils\Logger;
use Utils\PromptBuilder;

class AiTestGenerator
{
    private Client $client;
    private int $maxFixRetries = 5;

    public function __construct(private string $projectDir, private string $outputDir, private Logger $logger) {
        $this->client = Gemini::client($_ENV['GEMINI_API_KEY']);
        $this->logger->info('AiTestGenerator initialized.', [
            'projectDir' => $this->projectDir,
            'outputDir' => $this->outputDir
        ]);
    }

     private function getGenerationConfig(): GenerationConfig {
        return new GenerationConfig(temperature: 0.1);
    }

    private function getAvailableTools(): Tool {
        return new Tool(
            functionDeclarations: [
                new FunctionDeclaration(
                    name: 'get_file_content',
                    description: "Retrieves the full content of a specific file. Use to read source code or related tests.",
                    parameters: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'file_path' => new Schema(
                                type: DataType::STRING,
                                description: 'The relative path to the file from the project root (e.g., "src/VulnFileRead.php").'
                            ),
                        ],
                        required: ['file_path']
                    )
                ),
                new FunctionDeclaration(
                    name: 'list_directory_contents',
                    description: "Lists the files and directories inside a given path. Use to discover the `vulnerable_files` structure.",
                    parameters: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'path' => new Schema(
                                type: DataType::STRING,
                                description: 'The relative path of the directory to list (e.g., "vulnerable_files/users/").'
                            )
                        ],
                        required: ['path']
                    )
                )
            ]
        );
    }

    private function executeGetFileContent(string $filePath): string
    {
        $this->logger->info('Executing tool: get_file_content', ['requested_path' => $filePath]);
        
        if (str_starts_with($filePath, '/')) {
            $fullPath = $filePath;
        } else {
            $fullPath = $this->projectDir . DIRECTORY_SEPARATOR . $filePath;
        }

        $canonicalPath = realpath($fullPath);

        if ($canonicalPath === false) {
            $this->logger->warning('File not found for get_file_content', ['path' => $fullPath]);
            return "Error: File not found at path: {$filePath}";
        }
        
        $isWithinProject = str_starts_with($canonicalPath, realpath($this->projectDir));
        $isAllowedGlobal = str_starts_with($canonicalPath, '/app/');

        if (!$isWithinProject && !$isAllowedGlobal) {
             $this->logger->error('Access denied in get_file_content', ['path' => $filePath]);
            return "Error: Access denied. Attempted to read file outside of allowed directories.";
        }
        
        try {
            $content = FileHelper::readFile($canonicalPath);
            return "--- Content of " . basename($filePath) . " ---\n{$content}\n--- End Content ---";
        } catch (\Throwable $th) {
            $this->logger->error('Error reading file', ['path' => $canonicalPath, 'error' => $th->getMessage()]);
            return "Error reading file {$filePath}: {$th->getMessage()}";
        }
    }

    private function executeListDirectoryContents(string $path): array
    {
        $this->logger->info('Executing tool: list_directory_contents', ['requested_path' => $path]);
        $fullPath = $this->projectDir . DIRECTORY_SEPARATOR . $path;
        
        if (!is_dir($fullPath)) {
            $this->logger->warning('Directory not found for list_directory_contents', ['path' => $fullPath]);
            return ['error' => "Directory not found at {$fullPath}"];
        }

        try {
            $contents = array_values(array_diff(scandir($fullPath), ['.', '..']));
            return ['contents' => $contents];
        } catch (\Throwable $th) {
            $this->logger->error('Could not read directory', ['path' => $fullPath, 'error' => $th->getMessage()]);
            return ['error' => "Could not read directory {$fullPath}: {$th->getMessage()}"];
        }
    }
    
    private function handleFunctionCall(FunctionCall $functionCall): Content
    {
        $functionName = $functionCall->name;
        $args = $functionCall->args;
        $this->logger->info('Model requested function call.', ['name' => $functionName, 'args' => $args]);

        $result = match ($functionName) {
            'get_file_content' => ['content' => $this->executeGetFileContent($args['file_path'])],
            'list_directory_contents' => $this->executeListDirectoryContents($args['path']),
            default => ['error' => 'Unknown function called.'],
        };

        if ($functionName === 'unknown') {
            $this->logger->warning('Model called an unknown function.', ['name' => $functionName]);
        }

        return new Content(
            parts: [ new Part( functionResponse: new FunctionResponse(name: $functionName, response: $result)) ],
            role: Role::MODEL
        );
    }

    private function validateAndFixTest(array $generatedFileObject, ChatSession $chat): ?array
    {
        $currentCode = $generatedFileObject['code'];
        $filePath = $generatedFileObject['file_path'];

        $originalFilePath = $this->projectDir . DIRECTORY_SEPARATOR . $filePath;
        $originalCode = FileHelper::readFile($originalFilePath);

        for ($i = 1; $i <= $this->maxFixRetries; $i++) {
            file_put_contents($originalFilePath, $currentCode);

            $this->logger->info("Validating generated file (Attempt {$i}/{$this->maxFixRetries})", [
                'file' => $filePath,
            ]);

            $process = new Process([
                'vendor/bin/phpunit', 
                '--fail-on-warning', 
                '--process-isolation', 
                '--filter', 
                $filePath,
            ], $this->projectDir);
            $process->run();

            if ($process->isSuccessful()) {
                $this->logger->info("Validation successful for {$filePath}.");
                $generatedFileObject['code'] = $currentCode;
                return $generatedFileObject;
            }

            $errorOutput = $process->getOutput() . "\n" . $process->getErrorOutput();

            if ($i === $this->maxFixRetries) {
                $this->logger->error("Final validation attempt failed for {$filePath}.", ['error' => $errorOutput]);
                break; 
            }

            $this->logger->warning("Validation failed for {$filePath}. Asking AI for a fix.", ['attempt' => $i, 'error' => $errorOutput]);
            
            $fixPrompt = "Regenerate the test case to fix the issue. Usually syntax error.\nOutput from PHPUnit:```\n{$errorOutput}\n```";

            $response = $chat->sendMessage($fixPrompt);

            if (empty($response->parts())) {
                $this->logger->error("AI returned an empty response during fix attempt for {$filePath}.");
                continue;
            }

            try {
                $fixedFilesArray = JsonCleaner::parse($response->text());
                $fixedFileObject = (isset($fixedFilesArray[0])) ? $fixedFilesArray[0] : $fixedFilesArray;
                // Ensure the keys exist before trying to access them
                if (isset($fixedFileObject['code']) && isset($fixedFileObject['file_path'])) {
                     // Check that the AI didn't hallucinate a new file_path
                    if ($fixedFileObject['file_path'] === $filePath) {
                        $currentCode = $fixedFileObject['code'];
                    } else {
                        $this->logger->warning("AI returned a fix for a different file path. Ignoring.", [
                            'expected' => $filePath, 
                            'received' => $fixedFileObject['file_path']
                        ]);
                    }
                }
            } catch (\JsonException $e) {
                $this->logger->error("Failed to parse AI's fix response.", ['error' => $e->getMessage(), 'response' => $response->text()]);
            }
        }

        file_put_contents($originalFilePath, $originalCode);

        $this->logger->error("Failed to generate a valid file for {$filePath} after {$this->maxFixRetries} attempts.");
        return null;
    }

    /**
     * PHASE 1: Analyzes the system using function calling to identify vulnerable files.
     */
    public function analyzeSystems(string $projectStructure, array $phpUnitReport, string $mutationReport): array {

        $this->logger->info("Starting AiTestGenerator [Phase 1: Analysis]");

        $availableFilesContext = "The following files are available for analysis. Use the `get_file_content` tool to read them.\n\n";
        $availableFilesContext .= "- Project Structure: $projectStructure\n";
        $availableFilesContext .= "- Path Traversal Patterns: /patterns.json\n";
        $availableFilesContext .= "- Mutation Report: $mutationReport\n";
        
        foreach ($phpUnitReport as $reportPath) {
            $availableFilesContext .= "- PHPUnit Report: $reportPath\n";
        }

        $this->logger->debug('Sending analysis prompt with initial context.', ['context' => "Project Structure Files, Patterns, Initial PHPUnit Report and mutation report"]);

        $chat = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig($this->getGenerationConfig())
            ->withTool($this->getAvailableTools())
            ->startChat(history: [
                Content::parse(part: $availableFilesContext, role: Role::USER),
            ]);

        $response = $chat->sendMessage(PromptBuilder::analyzeSystem());

        $turn = 1;
        while ($response->parts()[0]->functionCall !== null) {
            $this->logger->info("Entering analysis function call loop, turn #{$turn}.");
            $functionCall = $response->parts()[0]->functionCall;
            $functionResponseContent = $this->handleFunctionCall($functionCall);
            $response = $chat->sendMessage($functionResponseContent);
            $turn++;
        }

        $this->logger->info('Received final analysis from the model.');
        $rawAnalysisOutput = $response->text();
        $this->logger->debug('Raw analysis output from model:', ['response' => $rawAnalysisOutput]);

        $analysisArray = JsonCleaner::parse($rawAnalysisOutput);
        $this->logger->info('Successfully parsed analysis output.', ['result_count' => count($analysisArray)]);
        
        $resultsPath = $this->outputDir . DIRECTORY_SEPARATOR . "analyze-results.json";
        file_put_contents($resultsPath, json_encode($analysisArray, JSON_PRETTY_PRINT));

        $this->logger->info('Analysis results saved.', ['path' => $resultsPath]);

        return $analysisArray;
    }

    public function generateTestCase(string $instruction, string $context, string $target, int $iterate): array
    {
        $this->logger->info("Starting AiTestGenerator [Phase 2: Generation] for iteration {$iterate}...");
        
        $this->logger->debug('Sending generation prompt.');

        $chat = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig($this->getGenerationConfig())
            ->withTool($this->getAvailableTools())
            ->startChat(
                history: [
                    Content::parse(part: $instruction, role: Role::USER),
                    Content::parse(part: $context, role: Role::USER),
                ]
            );

        $response = $chat->sendMessage($target);
        
        $turn = 1;
        while ($response->parts()[0]->functionCall !== null) {
            $this->logger->info("Entering generation function call loop, turn #{$turn}.");
            $functionCall = $response->parts()[0]->functionCall;
            $functionResponseContent = $this->handleFunctionCall($functionCall);
            $response = $chat->sendMessage($functionResponseContent);
            $turn++;
        }

        $this->logger->info('Received initial generated file(s) from the model.');

        if (empty($response->parts())) {
            // Handle safety-blocked responses
            throw new \Exception('Model returned an empty response during initial generation.');
        }

        $rawGeneratedOutput = $response->text();
        $this->logger->debug('Raw generation output from model:', ['response' => $rawGeneratedOutput]);
        
        $generatedFilesArray = JsonCleaner::parse($rawGeneratedOutput);
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . "generated-results-$iterate.json", json_encode($generatedFilesArray, JSON_PRETTY_PRINT));
        
        $validatedFiles = [];
        // The AI response might be a single object or an array of objects. Standardize it.
        if (isset($generatedFilesArray['file_path'])) {
            $generatedFilesArray = [$generatedFilesArray];
        }

        foreach ($generatedFilesArray as $fileObject) {
            // Pass the original analysis results to the validation loop to provide context for fixes.
            $validatedObject = $this->validateAndFixTest($fileObject, $chat);
            if ($validatedObject !== null) {
                $validatedFiles[] = $validatedObject;
            } else {
                $this->logger->error("Could not generate a valid file, skipping.", ['file_path' => $fileObject['file_path'] ?? 'unknown']);
            }
        }

        $this->logger->info('Successfully validated and fixed generation output.', ['file_count' => count($validatedFiles)]);

        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . "generated-results-$iterate-validated.json", json_encode($validatedFiles, JSON_PRETTY_PRINT));
        $this->logger->info('Generation results saved.', ['path' => $this->outputDir . DIRECTORY_SEPARATOR . "generated-results-$iterate.json"]);

        return $validatedFiles;
    }

    public function rewriteCode(array $generatedFilesArray): void
    {
        $this->logger->info('Rewriting code with generated files...', ['file_count' => count($generatedFilesArray)]);

        if (empty($generatedFilesArray)) {
             $this->logger->warning("No valid files were generated or fixed, so no files will be written.");
             return;
        }
        
        foreach ($generatedFilesArray as $fileObject) {
            if (!isset($fileObject['file_path']) || !isset($fileObject['code'])) {
                 $this->logger->warning('Skipping invalid entry in generated JSON array.', ['entry' => $fileObject]);
                 continue;
            }

            $dest = $this->projectDir . DIRECTORY_SEPARATOR . $fileObject['file_path'];
            $this->logger->info('Writing generated file.', ['path' => $dest]);

            $dir = dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($dest, $fileObject['code']);
        }

        $this->logger->info('Finished rewriting all generated files.');
    }
} 