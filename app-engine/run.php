<?php

require_once '/app/vendor/autoload.php';

use App\Main;

$repoUrl = $argv[1] ?? null;

Main::run($repoUrl);