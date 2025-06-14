<?php

namespace Pipeline;

use Gemini;
use Gemini\Client;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Data\UploadedFile;
use Gemini\Enums\DataType;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
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

    private function getProjectStructure($iterate): string {
        $this->logger->info('AIGenerator: Listing Project Structure using `git ls-files`');

        $process = new Process(['git', 'ls-files'], $this->projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $target = $this->outputDir . DIRECTORY_SEPARATOR . "git-lsfiles-output-$iterate.txt";
        if (file_put_contents($target, $process->getOutput())) {
            return $target;
        } else {
            throw new \Exception('Failed save git ls-files');
        }
    }

    public function analyzeSystems(array $phpUnitReport, $mutationReport, $iterate): string {
        $this->logger->info('AIGenerator: Analyzing Systems...');
        $fileToAnalyze = [
            [
                'path' => $this->getProjectStructure($iterate),
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
        
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . "analyze-payload-$iterate.json", $payload);
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . "analyze-results-$iterate.json", $resultsJson);

        return $this->outputDir . DIRECTORY_SEPARATOR . "analyze-results-$iterate.json";
    }
    
    public function generateTestCase($analyzerResultPath, $iterate): string {
        $this->logger->info('AIGenerator: Preparet to Generating Test Case...');

        $analyzerResults = json_decode(FileHelper::readFile($analyzerResultPath), true);
        
        $fileToAnalyze = [];
        $fileToAnalyze[] = [
            'path' => $analyzerResultPath,
            'mime' => MimeType::TEXT_PLAIN,
            'display' => 'Analyze Results'
        ];
        
        foreach ($analyzerResults as $item) {
            $fileToAnalyze[] = [
                'path' => $this->projectDir . DIRECTORY_SEPARATOR . $item['file'],
                'mime' => MimeType::TEXT_PLAIN,
                'display' => $item['file'],
            ];
            if (isset($item['related_test_files'])) {
                foreach ($item['related_test_files'] as $testFile) {
                    $flat[] = [
                        'path' => $this->projectDir . DIRECTORY_SEPARATOR . $testFile,
                        'mime' => MimeType::TEXT_PLAIN,
                        'display' => $testFile
                    ];
                }
            }
        }

        $this->uploadFiles($fileToAnalyze);

        $this->logger->info('AIGenerator: Generating Test Cases based on analyzer');

        $result = $this->client
            ->generativeModel(model: 'gemini-2.5-flash-preview-05-20')
            ->withGenerationConfig(
                generationConfig: new GenerationConfig(
                    responseMimeType: ResponseMimeType::APPLICATION_JSON,
                    responseSchema: new Schema(
                        type: DataType::ARRAY,
                        items: new Schema(
                            type: DataType::OBJECT,
                            properties: [
                                'file_path' => new Schema(
                                    type: DataType::STRING,
                                    description: 'The new file path for the test case',
                                    example: "tests/Security/PathTraversalTest.php",
                                ),
                                'code' => new Schema(
                                    type: DataType::STRING,
                                    description: 'The complete PHP code content of the generated or improved PHPUnit test case. This code should be a valid PHP file content',
                                    example: "<?php\n\nnamespace Tests\\Security;\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass FileAccessProtectionTest extends TestCase\n{\n  private \$tempDir;\n\n  protected function setUp(): void\n  {\n    \$this->tempDir = sys_get_temp_dir() . '/' . uniqid('test_files_');\n    mkdir(\$this->tempDir);\n    file_put_contents(\$this->tempDir . '/safe.txt', 'This is safe content.');\n    file_put_contents(\$this->tempDir . '/secret.txt', 'This is secret content.');\n  }\n\n  protected function tearDown(): void\n  {\n    // Clean up temporary directory and files\n    if (is_dir(\$this->tempDir)) {\n      \$files = array_diff(scandir(\$this->tempDir), array('.', '..'));\n      foreach (\$files as \$file) {\n        unlink(\$this->tempDir . '/' . \$file);\n      }\n      rmdir(\$this->tempDir);\n    }\n  }\n\n  /**\n  * @dataProvider traversalAttemptProvider\n  */\n  public function testAttemptedPathTraversalIsRejected(string \$inputPath):\n  void\n  {\n    // Assuming 'read_file_from_user_input' is the vulnerable function\n    // You would typically mock or integrate with the actual application code here.\n    // For demonstration, let's simulate a vulnerable function.\n    \$expectedException = false;\n    try {\n      \$filePath = realpath(\$this->tempDir . '/' . \$inputPath);\n      if (strpos(\$filePath, \$this->tempDir) !== 0) {\n        // If the realpath goes outside our temp directory, it's a traversal attempt\n        \$expectedException = true;\n        throw new \\Exception('Path traversal detected');\n      }\n      // Simulate file read\n      // \$content = file_get_contents(\$filePath);\n    } catch (\\Exception \$e) {\n      if (!\$expectedException) {\n        \$this->fail('Unexpected exception: ' . \$e->getMessage());\n      }\n      \$this->assertStringContainsString('Path traversal detected', \$e->getMessage());\n      return;\n    }\n    \$this->assertTrue(!\$expectedException, 'Path traversal was not rejected for: ' . \$inputPath);\n  }\n\n  public static function traversalAttemptProvider(): array\n  {\n    return [\n      'basic traversal' => ['../secret.txt'],\n      'double dot slash' => ['../../etc/passwd'],\n      'mixed slashes' => ['..\\/..\\/windows\\system32'],\n      'null byte injection' => ['safe.txt%00.png'],\n      'url encoded' => ['%2e%2e%2fsecret.txt'],\n      'double encoded' => ['%252e%252e%252fsecret.txt'],\n      'directory up one level' => ['safe/../secret.txt'],\n      'filename with extra dots' => ['safe.txt....'],\n      'unicode traversal' => ['%u002e%u002e%u002fsecret.txt'] // Example, assuming system decodes unicode\n    ];\n  }\n\n  public function testSafeFilePathIsAccessedCorrectly(): void\n  {\n    // Assuming 'read_file_from_user_input' is the function being tested\n    // In a real scenario, you'd call the actual method here.\n    \$inputPath = 'safe.txt';\n    \$filePath = realpath(\$this->tempDir . '/' . \$inputPath);\n    \$this->assertStringContainsString(\$this->tempDir . DIRECTORY_SEPARATOR . 'safe.txt', \$filePath);\n    \$this->assertFileExists(\$filePath);\n    // \$content = file_get_contents(\$filePath);\n    // \$this->assertEquals('This is safe content.', \$content);\n  }\n}\n"
                                ),
                            ],
                            required: ['file_path', 'code'],
                        )
                    )
                )
            )
            ->generateContent([
                ...$this->uploadedFiles,
                PromptBuilder::generatorPrompt()
            ]);
            
        $resultsJson = json_encode($result->json(), JSON_PRETTY_PRINT);
        $resultsPath = $this->outputDir . DIRECTORY_SEPARATOR . "generated-results-$iterate.json";
        file_put_contents($resultsPath, $resultsJson);

        return $resultsPath;
    }

    public function rewriteCode($generatedPath) : string {
        $this->logger->info('AIGenerator: Rewriting Code with New Test Case');

        $generatedCode = json_decode(FileHelper::readFile($generatedPath), true);

        foreach ($generatedCode as $item) {
            $dest = str_replace(['\\', '\/'], '/', $item['file_path']);
            file_put_contents(
                $this->projectDir . DIRECTORY_SEPARATOR . $dest, 
                $item['code'],
            );
        }

        return $this->projectDir . DIRECTORY_SEPARATOR . 'tests';
    }
} 