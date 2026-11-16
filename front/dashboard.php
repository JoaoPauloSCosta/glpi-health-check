<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Healthcheck\Compat;
use GlpiPlugin\Healthcheck\Page;
use GlpiPlugin\Healthcheck\ReportView;
use GlpiPlugin\Healthcheck\RunRepository;

// GLPI 10 runs this file directly and needs its bootstrap. Since GLPI 11 the kernel is already
// booted when the file is loaded, and including inc/includes.php is deprecated (GLPI 12).
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

$last = RunRepository::getLastRun();
$run  = $last;
if (isset($_GET['id']) && (int) $_GET['id'] > 0) {
    $run = RunRepository::getRun((int) $_GET['id']);
    if ($run === null) {
        Compat::notFound();
    }
}

Page::header();

$params = [
    'dashboard' => null,
    'is_last'   => true,
];
if ($run !== null) {
    $params['dashboard'] = ReportView::buildDashboard($run);
    $params['is_last']   = $last === null || (int) $last['id'] === (int) $run['id'];
    $params['counters']  = Page::counters($run);
    $params['trend']     = Page::trend(20, 320, 150);
    $urls = Page::urls();
    $params['urls'] = $urls;
    $params['urls']['export'] = $urls['export'] . '?id=' . (int) $run['id'];
}

Page::render('dashboard.html.twig', $params);

Page::footer();
