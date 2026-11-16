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

// GLPI 10 runs this file directly and needs its bootstrap. Since GLPI 11 the kernel is already
// booted when the file is loaded, and including inc/includes.php is deprecated (GLPI 12).
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

$format = (string) ($_GET['format'] ?? '');
$run    = isset($_GET['id']) ? RunRepository::getRun((int) $_GET['id']) : RunRepository::getLastRun();

if ($run === null || !in_array($format, Exporter::FORMATS, true)) {
    Session::addMessageAfterRedirect(__('Diagnóstico ou formato de exportação inválido.', 'healthcheck'), false, ERROR);
    Html::redirect(Menu::getFrontUrl('dashboard.php'));
}

(new Exporter($run))->send($format);
