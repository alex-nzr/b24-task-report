<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
$projectRoot = dirname(__DIR__);
$dotenv = new Dotenv();
$dotenv->load($projectRoot.'/.env.example');
if (is_file($projectRoot.'/.env'))
{
    $dotenv->load($projectRoot.'/.env');
}
if (is_file($projectRoot.'/.env.local'))
{
    $dotenv->load($projectRoot.'/.env.local');
}
