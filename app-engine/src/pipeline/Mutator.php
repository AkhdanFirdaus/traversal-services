<?php

namespace App\Pipeline;

class Mutator {
    public static function mutateVulnerableFiles(array $vulns): void {
        $mutantDir = './workspace/mutants';
        if (!is_dir($mutantDir)) mkdir($mutantDir, 0777, true);
    
        foreach ($vulns as $i => $vuln) {
            $mutated = preg_replace('/\$_(GET|POST|REQUEST)\[\'[^\']+\'\]/', '"../../etc/passwd"', $vuln['content']);
            $mutatedFile = $mutantDir . "/mutant_$i.php";
            file_put_contents($mutatedFile, $mutated);
        }
    }
}
