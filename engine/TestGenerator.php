<?php

namespace Engine;

class TestGenerator {
    public static function generateTestCases(array $vulns): void {
        $testDir = './workspace/generated-tests';
        if (!is_dir($testDir)) mkdir($testDir, 0777, true);
    
        foreach ($vulns as $i => $vuln) {
            $test = <<<PHP
    <?php
    use PHPUnit\Framework\TestCase;
    
    class TraversalTest_$i extends TestCase {
        public function testTraversalAttempt() {
            \$_GET['file'] = '../../etc/passwd';
            ob_start();
            include './workspace/mutants/mutant_$i.php';
            \$output = ob_get_clean();
            \$this->assertStringNotContainsString('root:', \$output);
        }
    }
    PHP;
            file_put_contents("$testDir/TraversalTest_$i.php", $test);
        }
    }
}
