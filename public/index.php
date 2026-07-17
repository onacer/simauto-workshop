<?php

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = new Dotenv();
$externalEnv = $_SERVER['SYMFONY_DOTENV_PATH'] ?? $_ENV['SYMFONY_DOTENV_PATH'] ?? false;
if ($externalEnv && file_exists($externalEnv)) {
    $dotenv->bootEnv($externalEnv);
} elseif (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv->bootEnv(dirname(__DIR__) . '/.env');
}

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
