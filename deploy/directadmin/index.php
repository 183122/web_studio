<?php

/*
 * Laravel front controller for shared hosting (DirectAdmin / cPanel).
 * Layout:  domains/<domain>/ai-studio   ← application (not web-accessible)
 *          domains/<domain>/public_html ← this file, the studio SPA, runtime bundle
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = __DIR__.'/../ai-studio';

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
