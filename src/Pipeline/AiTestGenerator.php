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
                ),
                new FunctionDeclaration(
                    name: 'list_directory_contents',
                    description: "Lists the files and directories inside a given project path. Use this to discover the `vulnerable_files` structure.",
                    parameters: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'path' => new Schema(
                                type: DataType::STRING,
                                description: 'The relative path of the directory to list from the project root (e.g., "vulnerable_files/users/").'
                            )
                        ],
                        required: ['path']
                    )
                )
            ]
        );
    }

    /**
     * Executes the file read operation requested by the model.
     */
    private function executeGetFileContent(string $filePath): string
    {
        $this->logger->info('Executing tool: get_file_content', ['requested_path' => $filePath]);
        $fullPath = $this->projectDir . DIRECTORY_SEPARATOR . $filePath;

        $canonicalPath = realpath($fullPath);

        if ($canonicalPath === false) {
            $this->logger->warning('File not found for get_file_content', ['path' => $fullPath]);
            return "Error: File not found at path: {$filePath}";
        }

        if (strpos($canonicalPath, realpath($this->projectDir)) !== 0) {
            $this->logger->error('Access denied attempt in get_file_content', [
                'requested_path' => $filePath,
                'canonical_path' => $canonicalPath,
                'project_dir' => realpath($this->projectDir)
            ]);
            return "Error: Access denied. Attempted to read file outside of the project directory.";
        }
        
        $this->logger->debug('Reading file content', ['path' => $canonicalPath]);
        try {
            $content = FileHelper::readFile($canonicalPath);
            return "--- Content of " . basename($filePath) . " ---\n{$content}\n--- End Content ---";
        } catch (\Throwable $th) {
            $this->logger->error('Error reading file', ['path' => $canonicalPath, 'error' => $th->getMessage()]);
            return "Error reading file {$filePath}: {$th->getMessage()}";
        }
    }

    /**
     * Executes the directory listing operation requested by the model.
     */
    private function executeListDirectoryContents(string $path): array
    {
        $this->logger->info('Executing tool: list_directory_contents', ['requested_path' => $path]);
        $fullPath = $this->projectDir . DIRECTORY_SEPARATOR . $path;

        if (!is_dir($fullPath)) {
            $this->logger->warning('Directory not found for list_directory_contents', ['path' => $fullPath]);
            return ['error' => "Directory not found at {$fullPath}"];
        }

        try {
            $this->logger->debug('Scanning directory', ['path' => $fullPath]);
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

        if ($functionCall->name === 'get_file_content') {
            $result = $this->executeGetFileContent($args['file_path']);
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

        if ($functionName === 'list_directory_contents') {
            $result = $this->executeListDirectoryContents($args['path']);
            return new Content(
                parts: [ new Part( functionResponse: new FunctionResponse(name: $functionName, response: $result)) ],
                role: Role::MODEL
            );
        }

        $this->logger->warning('Model called an unknown function.', ['name' => $functionName]);

        return new Content(
            parts: [ new Part( functionResponse: new FunctionResponse(name: $functionCall->name, response: ['error' => 'Unknown function called.']) ) ],
            role: Role::MODEL
        );
    }

    /**
     * PHASE 1: Analyzes the system using function calling to identify vulnerable files.
     */
    public function analyzeSystems(string $projectStructure, array $phpUnitReport, string $mutationReport, $iterate): array {

        $this->logger->info("Starting AiTestGenerator [Phase 1: Analysis] for iteration {$iterate}...");

        $availableFilesContext = "The following files are available for analysis. Use the `get_file_content` tool to read them.\n\n";
        $availableFilesContext .= "- Project Structure: $projectStructure\n";
        $availableFilesContext .= "- Path Traversal Patterns: /patterns.json\n";
        $availableFilesContext .= "- Mutation Report: $mutationReport\n";
        
        foreach ($phpUnitReport as $reportPath) {
            $availableFilesContext .= "- PHPUnit Report: $reportPath\n";
        }

        $this->logger->debug('Sending analysis prompt with initial context.', ['context' => $availableFilesContext]);

        $chat = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig($this->getGenerationConfig())
            ->withTool($this->getFileContentTool())
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
        
        $resultsPath = $this->outputDir . DIRECTORY_SEPARATOR . "analyze-results-$iterate.json";
        file_put_contents($resultsPath, json_encode($analysisArray, JSON_PRETTY_PRINT));

        $this->logger->info('Analysis results saved.', ['path' => $resultsPath]);

        return $analysisArray;
    }

    /**
     * New private method to validate and fix a generated test case.
     */
    private function validateAndFixTest(array $generatedFileObject, ChatSession $chat): ?array
    {
        $currentCode = $generatedFileObject['code'];
        $filePath = $generatedFileObject['file_path'];

        for ($i = 1; $i <= $this->maxFixRetries; $i++) {
            // Write the current version of the code to a temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'test_') . '.php';
            file_put_contents($tempFile, $currentCode);

            // Extract class name from the test file path to use with --filter
            $className = basename($filePath, '.php');

            $this->logger->info("Validating generated test (Attempt {$i}/{$this->maxFixRetries})", [
                'test_class' => $className,
                'temp_path' => $tempFile
            ]);

            $process = new Process([
                'vendor/bin/phpunit',
                '--fail-on-warning',
                '--fail-on-risky',
                '--process-isolation',
                '--filter',
                $className
            ], $this->projectDir);

            $process->run();

            if ($process->isSuccessful()) {
                $this->logger->info("Validation successful for {$className}.");
                unlink($tempFile);
                $generatedFileObject['code'] = $currentCode; // Ensure the final good code is set
                return $generatedFileObject;
            }

            // If it failed, send the error back to the AI for a fix
            $errorOutput = $process->getOutput() . "\n" . $process->getErrorOutput();
            $this->logger->warning("Validation failed for {$className}. Asking AI for a fix.", [
                'attempt' => $i,
                'error' => $errorOutput
            ]);

            $fixPrompt = "The previously generated test failed with the following error. Please fix the code. Do not apologize, just provide the corrected JSON object.\n\nPHPUnit Output:\n```\n{$errorOutput}\n```";
            
            $response = $chat->sendMessage($fixPrompt);

            if (empty($response->parts())) {
                $this->logger->error("AI returned an empty response during fix attempt.", ['test_class' => $className]);
                continue; // Try again or fail
            }

            try {
                $fixedFileObject = JsonCleaner::parse($response->text());
                // The AI might return an array or a single object. Standardize to a single object.
                $currentCode = (isset($fixedFileObject[0])) ? $fixedFileObject[0]['code'] : $fixedFileObject['code'];
            } catch (\JsonException $e) {
                $this->logger->error("Failed to parse AI's fix response.", ['error' => $e->getMessage(), 'response' => $response->text()]);
                continue; // The fix was invalid JSON, try asking again
            } finally {
                 unlink($tempFile);
            }
        }

        $this->logger->error("Failed to fix test case {$className} after {$this->maxFixRetries} attempts.");
        return null;
    }
    
    /**
     * PHASE 2: Generates the test case based on the analysis.
     */
    public function generateTestCase(array $analyzerResults, $iterate): array {
        $this->logger->info("Starting AiTestGenerator [Phase 2: Generation] for iteration {$iterate}...");

        $analyzerResultsJson = json_encode($analyzerResults, JSON_PRETTY_PRINT);
        
        $chat = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig($this->getGenerationConfig())
            ->withTool($this->getFileContentTool())
            ->startChat();
        
        $finalPrompt = PromptBuilder::generatorPrompt() . "\n\n# Analysis to Address:\n" . $analyzerResultsJson;;
        $response = $chat->sendMessage($finalPrompt);

        $this->logger->debug('Sending generation prompt.');

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
            throw new \Exception('Model returned an empty response during initial generation.');
        }

        $rawGeneratedOutput = $response->text();
        $this->logger->debug('Raw generation output from model:', ['response' => $rawGeneratedOutput]);
        
        $generatedFilesArray = JsonCleaner::parse($rawGeneratedOutput);
        
        $validatedFiles = [];
        if (isset($generatedFilesArray['file_path'])) {
            $generatedFilesArray = [$generatedFilesArray];
        }

        foreach ($generatedFilesArray as $fileObject) {
            // We pass the main chat session to the validation loop so it can ask for fixes.
            $validatedObject = $this->validateAndFixTest($fileObject, $chat);
            if ($validatedObject !== null) {
                $validatedFiles[] = $validatedObject;
            } else {
                $this->logger->error("Could not generate a valid file, skipping.", ['file_path' => $fileObject['file_path'] ?? 'unknown']);
            }
        }

        $this->logger->info('Successfully validated and fixed generation output.', ['file_count' => count($validatedFiles)]);

        $resultsPath = $this->outputDir . DIRECTORY_SEPARATOR . "generated-results-$iterate.json";
        file_put_contents($resultsPath, json_encode($validatedFiles, JSON_PRETTY_PRINT));
        $this->logger->info('Generation results saved.', ['path' => $resultsPath]);

        return $validatedFiles;
    }

    public function rewriteCode(array $generatedFilesArray) : string
    {
        $this->logger->info('Rewriting code with generated files...', ['file_count' => count($generatedFilesArray)]);

        if (empty($generatedFilesArray)) {
                throw new \Exception("Generated files array is empty.");
        }
        
        // The JSON from the model should be an array of file objects.
        // Let's ensure it's an array of objects for safety.
        if (!is_array($generatedFilesArray[0] ?? null)) {
            $this->logger->warning('Generated result is a single object, wrapping it in an array for processing.');
            $generatedFilesArray = [$generatedFilesArray];
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

        return $this->projectDir . DIRECTORY_SEPARATOR . 'tests';
    }
} 