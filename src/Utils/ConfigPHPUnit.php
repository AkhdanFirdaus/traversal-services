<?php

namespace Utils;

class ConfigPHPUnit {
    public function __construct( 
        private string $projectDir, 
        private string $testDir,
        private string $outputDir,
    ) {}

    public function get() {
        $bootstrapPath = $this->projectDir . '/vendor/autoload.php';

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

        return $dom;
    }

    public function write(): void {
        try {
            $dom = $this->get();
            $dom->save($this->projectDir . DIRECTORY_SEPARATOR . 'phpunit.xml');
        } catch (\Throwable $th) {
            throw new \RuntimeException("Failed to create phpunit configuration file");
        }
    }

    public function getConfigPath(): string {
        return $this->projectDir . DIRECTORY_SEPARATOR . 'phpunit.xml';
    }
}