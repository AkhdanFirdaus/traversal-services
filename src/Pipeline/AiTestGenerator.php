<?php

namespace Pipeline;

use Gemini\Client;
use Gemini;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Enums\DataType;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process as ProcessProcess;
use Utils\FileHelper;

class AiTestGenerator
{
    private Client $client;

    public function __construct() {
        $this->client = Gemini::client($_ENV['GEMINI_API_KEY']);
    }

    private function uploadFiles(array $files) : void{
        foreach ($files as $file) {
            $meta = $this->client->files()->upload(
                filename: $file,
                mimeType: MimeType::TEXT_PLAIN,
                displayName: basename($file),
            );
        }

        do {
            sleep(2);
            $meta = $this->client->files()->metadataGet($meta->uri);
        } while (!$meta->state->complete());

        if ($meta->state === \Gemini\Enums\FileState::Failed) {
            throw new \Exception('Failed upload files');
        }
    }

    private function getProjectStructure($projectDir): string {
        $process = new ProcessProcess(['git', 'ls-files'], $projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $target = $projectDir . DIRECTORY_SEPARATOR . 'git-lsfiles-output.txt';
        if (FileHelper::writeFile($target, $process->getOutput())) {
            return $target;
        } else {
            throw new \Exception('Failed save file');
        }
    }

    public function analyzeSystems($projectDir, $phpUnitReport, $mutationReport) {
        // git ls-files, phpunit report, mutation testing report, patterns.json
        $fileToAnalyze = [
            $this->getProjectStructure($projectDir),
            $phpUnitReport,
            $mutationReport,
            
        ];
        $this->uploadFiles($fileToAnalyze);
        $this->client
            ->generativeModel(model: 'gemini-2.5-flash')
            ->withGenerationConfig(
                generationConfig: new GenerationConfig(
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
            );
        
    }
} 