<?php

namespace App\Pipeline;

class InfectionRunner {
    public static function run(): float {
        exec('/app/vendor/bin/phpunit --testdox', $out1);
        exec('/app/vendor/bin/infection', $out2);
    
        foreach ($out2 as $line) {
            if (preg_match('/Mutation Score Indicator.*?(\d+(\.\d+)?)/', $line, $match)) {
                return (float) $match[1];
            }
        }
    
        return 0.0;
    }

    public static function run2(): array {
        exec('/app/vendor/bin/phpunit --testdox', $out1);
        exec('/app/vendor/bin/infection', $out2);
    
        return $out2;
    }
}
