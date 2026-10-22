<?php

/*
 * Porta de entrada na hospedagem compartilhada: este arquivo fica na pasta pública do
 * subdomínio (public_html/multmec) e o resto do sistema fica fora dela, em multmec-app.
 * É o public/index.php do Laravel com os caminhos ajustados. deploy/publicar.sh copia ele.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appDir = dirname(__DIR__, 2).'/multmec-app';

if (file_exists($maintenance = $appDir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appDir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appDir.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
