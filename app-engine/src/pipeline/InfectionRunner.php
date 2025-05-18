<?php

namespace App\Pipeline;

class InfectionRunner {
    public static function run(): float {
        exec('vendor/bin/phpunit --testdox', $out1);
        exec('vendor/bin/infection', $out2);
    
        foreach ($out2 as $line) {
            if (preg_match('/Mutation Score Indicator.*?(\d+(\.\d+)?)/', $line, $match)) {
                return (float) $match[1];
            }
        }
    
        return 0.0;
    }
}
