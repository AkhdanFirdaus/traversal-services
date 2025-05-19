<?php

namespace App;

use App\Helpers\Utils;

class ResultComparer {
    public function __construct(private string $reportDir) {
        @mkdir("$reportDir/final", 0777, true);
    }

    public function run(string $testsDir, string $finalDir): void {
        $before = json_decode(file_get_contents("$this->reportDir/infection_before.json"), true);
        $after = json_decode(file_get_contents("$this->reportDir/infection_after.json"), true);
        $msiBefore = $before['metrics']['msi'] ?? 0;
        $msiAfter = $after['metrics']['msi'] ?? 0;

        if ($msiAfter > $msiBefore) {
            foreach (glob("$testsDir/*.php") as $file) {
                copy($file, "$finalDir/" . basename($file));
            }
        }

        Utils::log("Result Comparer", "$this->reportDir/result_comparer.json");
    }
}