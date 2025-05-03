<?php

function detectTraversalRisks(array $phpFiles): array {
    $vulns = [];

    foreach ($phpFiles as $file) {
        $content = file_get_contents($file);

        if (preg_match('/\$_(GET|POST|REQUEST)/', $content) &&
            preg_match('/(include|require|fopen|file_get_contents|readfile)/', $content)) {
            $vulns[] = ['file' => $file, 'content' => $content];
        }
    }

    return $vulns;
}
