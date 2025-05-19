<?php

namespace App;

use App\helpers\AIClient;
use App\Helpers\Utils;

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