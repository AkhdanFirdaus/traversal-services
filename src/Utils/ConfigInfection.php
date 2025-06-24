<?php

namespace Utils;

class ConfigInfection {
    public function __construct(
        private string $projectDir, 
        private string $testDir,
        private string $outputDir,
    ) {}

    private function get(): array {
        return [
            "\$schema" => "/app/vendor/infection/infection/resources/schema.json",
            "bootstrap" => "vendor/autoload.php",
            "phpUnit" => ["configDir" => "."],
            "source" => [
                "directories" => [
                    "src", 
                    $this->testDir
                ], 
                "excludes" => ['vendor']
            ],
            "logs" => [
                "text" => "$this->outputDir/infection.log",
                "html" => "$this->outputDir/infection.html",
                "summary" => "$this->outputDir/summary.log",
                "json" => "$this->outputDir/infection-report.json",
                "summaryJson" => "$this->outputDir/summary.json"
            ],
            "mutators" => [
                "@default" => true
            ],
            "testFramework" => "phpunit",
        ];
    }

    public function write(): void {
        $content = json_encode($this->get(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (!file_put_contents($this->projectDir . DIRECTORY_SEPARATOR . 'infection.json5', $content)) {
            throw new \RuntimeException("Failed to create Infection configuration file");
        }
    }

    public function getConfigPath(): string {
        return $this->projectDir . DIRECTORY_SEPARATOR . 'infection.json';
    }
}