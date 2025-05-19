<?php

namespace App;

use App\Helpers\Utils;

class InfectionRunner {
    public function __construct(private string $repoDir, private string $reportDir, private string $src = 'src', private string $test = 'tests') {
        @mkdir($reportDir, 0777, true);
    }

    public function run(string $label): string {
        $infectionConfig = $this->repoDir . '/infection.json5';

        if (!file_exists($infectionConfig)) {
            file_put_contents($infectionConfig, json_encode([
                'source' => ['directories' => [$this->src]],
                'logs' => [
                    'json' => "$this->reportDir/infection_$label.json"
                ]
            ], JSON_PRETTY_PRINT));
        }

        $destination = "$this->reportDir/infection_$label.json";
        $cmd = "/vendor/bin/infection --threads=1 --min-msi=0 --min-covered-msi=0 ".
               "--logger-json=" . escapeshellarg($destination);
        shell_exec($cmd);

        Utils::log("Infection $label", $destination);
        return $destination;
    }
}
