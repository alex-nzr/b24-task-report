<?php

use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/config/bootstrap.php';
$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
