<?php

namespace App\Engine;

class Analyzer {
    public static function analyzeSourceCode(string $dir): array {
        $phpFiles = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if (!$file->isDir() && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }
        return $phpFiles;
    }
}

