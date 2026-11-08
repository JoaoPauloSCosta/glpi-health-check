<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Healthcheck\Exporter;
use GlpiPlugin\Healthcheck\Menu;
use GlpiPlugin\Healthcheck\RunRepository;

include('../../../inc/includes.php');

Session::checkRight('config', READ);

$format = (string) ($_GET['format'] ?? '');
$run    = isset($_GET['id']) ? RunRepository::getRun((int) $_GET['id']) : RunRepository::getLastRun();

if ($run === null || !in_array($format, Exporter::FORMATS, true)) {
    Session::addMessageAfterRedirect(__('Diagnóstico ou formato de exportação inválido.', 'healthcheck'), false, ERROR);
    Html::redirect(Menu::getFrontUrl('dashboard.php'));
}

(new Exporter($run))->send($format);
