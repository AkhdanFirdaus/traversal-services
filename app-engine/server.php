<?php

require_once '/app/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

header("Content-Type: application/json");

require_once '/app/src/routes.php';
