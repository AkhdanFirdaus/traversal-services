<?php

namespace App;

use App\Helpers\Utils;

class RepositoryCloner {
    public function __construct(private string $baseDir) {
        @mkdir("$this->baseDir/src", 0777, true);
    }

    public function run(string $repoUrl): string {
        $repoDir = "$this->baseDir/src";
        if (file_exists($repoDir)) {
            shell_exec("rm -rf " . escapeshellarg($repoDir));
        }
        shell_exec("git clone " . escapeshellarg($repoUrl) . " " . escapeshellarg($repoDir));

        Utils::log("1. Clone", $repoDir);
        return $repoDir;
    }
}