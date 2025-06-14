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
use Gemini\Data\UploadedFile;
use Gemini\Enums\DataType;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Gemini\Enums\Role;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Utils\FileHelper;
use Utils\Logger;
use Utils\PromptBuilder;

class AiTestGenerator
{
    private Client $client;
    private array $uploadedFiles = [];

    public function __construct(private string $projectDir, private string $outputDir, private Logger $logger) {
        $this->client = Gemini::client($_ENV['GEMINI_API_KEY']);
    }

    private function uploadFiles(array $files) : void {
        $this->logger->info('AIGenerator: Uploading Files', [
            'files' => array_map(fn ($item) => $item['path'], $files),
        ]);

        foreach ($files as $file) {
            $meta = $this->client->files()->upload(
                filename: $file['path'],
                mimeType: $file['mime'],
                displayName: basename($file['path']),
            );

            do {
                sleep(2);
                $meta = $this->client->files()->metadataGet($meta->uri);
            } while (!$meta->state->complete());
    
            if ($meta->state === \Gemini\Enums\FileState::Failed) {
                throw new \Exception('Failed upload files: \n' . json_encode($meta->toArray(), JSON_PRETTY_PRINT));
            }

            $this->uploadedFiles[] = new UploadedFile(
                fileUri: $meta->uri,
                mimeType: $file['mime'],
            );
        }

    }

    

    public function analyzeSystems(string $projectStructurePath, array $phpUnitReport, $mutationReport): mixed {
        $this->logger->info('AIGenerator: Analyzing Systems...');
        $fileToAnalyze = [
            [
                'path' => $projectStructurePath,
                'mime' => MimeType::TEXT_PLAIN,
                'display' => 'git ls-config'
            ],
            [
                'path' => '/app/config/patterns.json',
                'mime' => MimeType::TEXT_PLAIN,
                'display' => 'path traversal patterns'
            ],
            [
                'path' => $mutationReport,
                'mime' => MimeType::TEXT_PLAIN,
                'display' => 'Mutation testing Report'
            ],
        ];

        foreach ($phpUnitReport as $report) {
            $fileToAnalyze[] = [
                'path' => $report,
                'mime' => MimeType::TEXT_XML,
                'display' => 'PHP Unit Report',
            ];
        }

        $this->uploadFiles($fileToAnalyze);

        $this->logger->info('AIGenerator: Generating Analyzer Results');

        $results = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig(
                generationConfig: new GenerationConfig(
                    temperature: 0.1,
                    responseMimeType: ResponseMimeType::APPLICATION_JSON,
                    responseSchema: new Schema(
                        type: DataType::ARRAY,
                        items: new Schema(
                            type: DataType::OBJECT,
                            properties: [
                                'file' => new Schema(
                                    type: DataType::STRING,
                                    description: 'Path to the PHP file',
                                    example: "src/controllers/FileController.php",
                                ),
                                'reason' => new Schema(
                                    type: DataType::STRING,
                                    description: 'Concise reason for selection related to directory/path traversal (e.g., "Uses user input in include without sanitization.", "Low mutation score in path validation for file upload.", "Handles dynamic file download based on user input.")',
                                    example: "Handles dynamic file access via `file_get_contents` with unsanitized user input.",
                                ),
                                'related_test_files' => new Schema(
                                    type: DataType::ARRAY,
                                    description: 'List of related test files already present.',
                                    items: new Schema(
                                        type: DataType::STRING,
                                        example: "tests/FileControllerTest.php",
                                    ),
                                ),
                            ],
                            required: ['file', 'reason', 'related_test_files']
                        )
                    )
                )
            )->generateContent([
                ...$this->uploadedFiles,
                PromptBuilder::analyzeSystem(),
            ]);

        $payload = json_encode($fileToAnalyze, JSON_PRETTY_PRINT);
        $resultsJson = json_encode($results->json(), JSON_PRETTY_PRINT);
        
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . "analyze-payload.json", $payload);
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . "analyze-results.json", $resultsJson);

        return [
            'analyze_results' => FileHelper::readFile($this->outputDir . DIRECTORY_SEPARATOR . "analyze-results.json"),
            'project_structure' => FileHelper::readFile($fileToAnalyze[0]['path']),
            'mutation_report' => FileHelper::readFile($fileToAnalyze[2]['path']),
        ];
    }
    
    private function executeGetFileContent(string $filePath): string {
        $fullPath = $this->projectDir . DIRECTORY_SEPARATOR . $filePath;

        $this->logger->info('Function Call: Reading file', ['path' => $fullPath]);

        try {
            $content = FileHelper::readFile($fullPath);
            return "--- Content of {$filePath} ---\n{$content}\n--- End Content ---";
        } catch (\Throwable $th) {
            return "Error reading file {$filePath}: {$th->getMessage()}";
        }
    }

    private function getGenerationConfig(): GenerationConfig {
        return new GenerationConfig(temperature: 0.1);
    }

    private function getFileContentTool(): Tool {
        return new Tool(
            functionDeclarations: [
                new FunctionDeclaration(
                    name: 'get_file_content',
                    description: "Retrieves file content from the project directory",
                    parameters: new Schema(
                        type: DataType::OBJECT,
                        properties: [
                            'file_path' => new Schema(
                                type: DataType::STRING,
                                description: 'Relative path to the source code file from the project root'
                            ),
                        ],
                        required: ['file_path']
                    ),
                )
            ]
        );
    }

    private function handleFunctionCall(FunctionCall $functionCall): Content {
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
                role: Role::MODEL,
            );
        }

        return new Content(
            parts: [
                new Part(
                    functionResponse: new FunctionResponse(
                        name: $functionCall->name,
                        response: ['error' => 'Unknown function called.']
                    )
                )
            ],
            role: Role::MODEL,
        );
    }

    public function generateTestCase(
        string $analyzerResults,
        string $projectStructure,
        array $unitResults,
        string $mutationReport,
        $iterate
    ): string {
        $this->logger->info('AIGenerator: Generating Test Cases with multi-turn conversation');
        
        $buildUnitContext = [];

        foreach ($unitResults as $key => $value) {
            $buildUnitContext[] = Content::parse(
                part: "Unit Test Report for $key: \n$value",
                role: Role::USER,
            );
        }

        $chat = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig(generationConfig: $this->getGenerationConfig())
            ->withTool($this->getFileContentTool())
            ->startChat(history: [
                // sebagai konteks
                Content::parse(
                    part: "Project Directory Structure:\n$projectStructure",
                    role: Role::USER,
                ),
                ...$buildUnitContext,
                Content::parse(
                    part: "Mutation Report:\n$mutationReport",
                    role: Role::USER,
                ),
                Content::parse(
                    part: "Analysis Results:\n$analyzerResults",
                    role: Role::MODEL,
                ),
            ]);

        $response = $chat->sendMessage(PromptBuilder::generatorPrompt());

        while ($response->parts()[0]->functionCall !== null) {
            $functionCall = $response->parts()[0]->functionCall;

            $this->logger->info('AIGenerator: Model requested function call.', [
                'name' => $functionCall->name,
                'args' => $functionCall->args,
            ]);
            
            $functionResponseContent = $this->handleFunctionCall($functionCall);
            $response = $chat->sendMessage($functionResponseContent);
        }

        $this->logger->info('AIGenerator: Received final response from the model.');

        $finalResponseJson = substr($response->text(), 7, -3);
        $finalResponseJson = preg_replace('/^json\\n|$/m', '', trim($finalResponseJson));

        $resultsPath = $this->outputDir . DIRECTORY_SEPARATOR . "generated-results-$iterate.json";
        file_put_contents($resultsPath, $finalResponseJson);

        return $finalResponseJson;
    }

    public function rewriteCode($generated) : string {
        try {
            $this->logger->info('AIGenerator: Rewriting Code with New Test Case');

            $generatedCode = json_decode($generated, true);

            $dest = $generatedCode['file_path'];
            file_put_contents(
                $this->projectDir . DIRECTORY_SEPARATOR . $dest, 
                $generatedCode['code'],
            );

            return $this->projectDir . DIRECTORY_SEPARATOR . 'tests';

        } catch (\Throwable $th) {
            $this->logger->error('Failed Rewrite File', [
                'stack' => $th->getTrace(),
                'error' => $th->getMessage(),
            ]);
            throw $th;
        }
    }
} 