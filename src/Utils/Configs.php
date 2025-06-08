<?php

namespace Utils;

class InfectionConfig {
    public function __construct(
        private string $projectDir, 
        private string $testDir,
        private string $outputDir,
    ) {}

    private function get(): array {
        return [
            "\$schema" => $this->projectDir . "/vendor/infection/infection/resources/schema.json",
            "bootstrap" => $this->projectDir . "/vendor/autoload.php",
            "phpUnit" => ["configDir" => $this->projectDir],
            "source" => [
                "directories" => [
                    $this->projectDir . "/src", 
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
        if (!FileHelper::writeFile($this->projectDir, $content)) {
            throw new \RuntimeException("Failed to create Infection configuration file");
        }
    }

    public function getConfigPath(): string {
        return $this->projectDir . '/' . 'infection.json';
    }
}

class PHPUnitConfig {
    public function __construct( 
        private string $projectDir, 
        private string $testDir,
        private string $outputDir,
    ) {}

    public function get() {
        $bootstrapPath = $this->projectDir . '/vendor/bootstrap.php';

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        // <phpunit>
        $phpunit = $dom->createElement('phpunit');
        $phpunit->setAttribute('bootstrap', $bootstrapPath);
        $phpunit->setAttribute('executionOrder', 'random');
        $phpunit->setAttribute('resolveDependencies', 'true');
        $dom->appendChild($phpunit);

        // <testsuites>
        $testsuites = $dom->createElement('testsuites');
        $phpunit->appendChild($testsuites);

        // <testsuite>
        $testsuite = $dom->createElement('testsuite');
        $testsuite->setAttribute('name', 'Path Traversal Mutated Tests');
        $testsuites->appendChild($testsuite);

        $includeDir = $dom->createElement('directory', $this->testDir);
        $includeDir->setAttribute('suffix', 'Test.php');
        $testsuite->appendChild($includeDir);

        // Output
        return $dom->saveXML();
    }

    public function write(): void {
        $content = json_encode($this->get(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!FileHelper::writeFile($this->projectDir, $content)) {
            throw new \RuntimeException("Failed to create Infection configuration file");
        }
    }

    public function getConfigPath(): string {
        return $this->projectDir . '/' . 'phpunit.xml';
    }
}