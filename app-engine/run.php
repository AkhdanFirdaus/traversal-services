<?php

require_once '/app/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Main;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$repoUrl = $argv[1] ?? null;

Main::run($repoUrl);