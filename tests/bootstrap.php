<?php

require dirname(__DIR__).'/config/bootstrap.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = 'test-only-secret';
$_SERVER['REPORT_LOGIN'] = $_ENV['REPORT_LOGIN'] = 'report';
$_SERVER['REPORT_PASSWORD_HASH'] = $_ENV['REPORT_PASSWORD_HASH'] = password_hash('test-password', PASSWORD_BCRYPT, ['cost' => 4]);
