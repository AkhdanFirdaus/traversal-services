<?php

namespace App\Helpers;

use App\Helpers\Utils;
use Anthropic;
use Gemini;
use OpenAI;

// create abstraact class for Client, contain sendPrompt method
class Client {
    protected $client;

    public function __construct($apiKey) {
        $this->client = $this->createClient($apiKey);
    }

    protected function createClient($apiKey) {
        // This method should be overridden in subclasses
        throw new \Exception("createClient method not implemented");
    }

    public function sendPrompt($prompt) {
        // This method should be overridden in subclasses
        throw new \Exception("sendPrompt method not implemented");
    }
}

class OpenAIClient extends Client {
    public function __construct($apiKey) {
        $this->client = OpenAI::client($apiKey);
    }

    public function getClient() {
        return $this->client;
    }

    public function sendPrompt($prompt) {
        return $this->client->completions()->create([
            'model' => 'gpt-4o',
            'prompt' => $prompt,
        ]);
    }
}

class AnthropicClient extends Client {
    public function __construct($apiKey) {
        $this->client = Anthropic::client($apiKey);
    }

    public function getClient() {
        return $this->client;
    }

    public function sendPrompt($prompt) {
        return $this->client->messages()->create([
            'model' => 'claude-3',
            'messages' => $prompt,
        ]);
    }
}

class GeminiClient extends Client {
    public function __construct($apiKey) {
        $this->client = Gemini::client($apiKey);
    }

    public function getClient() {
        return $this->client;
    }

    public function sendPrompt($prompt) {
        return $this->client->geminiPro()->generate($prompt);
    }
}

class AIClient {
    private $agent;

    public function __construct($agent) {
        switch ($agent) {
            case 'openai':
                $this->agent = new OpenAIClient($_ENV['OPENAI_API_KEY']);
                break;
            case 'anthropic':
                $this->agent = new AnthropicClient($_ENV['ANTHROPIC_API_KEY']);
                break;
            case 'gemini':
                $this->agent = new GeminiClient($_ENV['GEMINI_API_KEY']);
                break;
            default:
                throw new \Exception("Unsupported agent: $agent");
        }
    }

    public function getClient() {
        return $this->agent->getClient();
    }

    public function buildPrompt($prompt): string {
        return "You are a security-focused AI assistant. Given the following PHP vulnerability context, generate a PHPUnit-compatible test case that attempts to exploit or validate the identified issue.
        Context: $prompt

        Make sure to:
        - Use proper PHP syntax
        - Include '@test' or 'public function test...()' methods
        - Target the related vulnerable method or file
        - Don't fix the vulnerability, only try to test it

        Return ONLY the test code in your response.
        `";
    }
    
    public function sendPrompt($prompt) {
        $prompt = $this->buildPrompt($prompt);
        return $this->agent->sendPrompt($prompt);
    }
}

class AiAgent {
    private AIClient $aiClient;

    public function __construct(private string $type, private array $report, private string $testsDir, private string $aiDir) {
        @mkdir($testsDir, 0777, true);
        @mkdir($aiDir, 0777, true);
        $this->aiClient = new AIClient($type);
    }

    public function run(): array {
        $results = [];
        
        foreach ($this->report as $i => $finding) {
            // "File: {$finding['file']}\n\n" .
            // "Code Snippet:\n{$finding['code_snippet']}\n\n"
            $prompt = $this->aiClient->buildPrompt($finding['code_snippet']);
            $result = $this->aiClient->sendPrompt($prompt);

            file_put_contents($this->aiDir . "/prompt_" . $finding['file'] . "txt", $prompt);
            file_put_contents($this->testsDir . "/" . $finding['file'] . "_generated$i.php", $result);

            $results[] = [
                'file' => $finding['file'],
                'test_file' => $finding['file'] . "generated$i.php",
                'results' => $result,
            ];
        }

        Utils::log("3. AI Results", $prompt);

        return $results;
    }
}