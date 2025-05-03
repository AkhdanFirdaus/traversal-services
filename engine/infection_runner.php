<?php

function runInfection(): float {
    exec('vendor/bin/phpunit --coverage-xml=build/coverage', $out1);
    exec('vendor/bin/infection --coverage=build/coverage --threads=2 --only-covered', $out2);

    foreach ($out2 as $line) {
        if (preg_match('/Mutation Score Indicator.*?(\d+(\.\d+)?)/', $line, $match)) {
            return (float) $match[1];
        }
    }

    return 0.0;
}
