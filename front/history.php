<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Healthcheck\Menu;
use GlpiPlugin\Healthcheck\Page;
use GlpiPlugin\Healthcheck\ReportView;
use GlpiPlugin\Healthcheck\RunRepository;
use GlpiPlugin\Healthcheck\Runner;

include('../../../inc/includes.php');

Session::checkRight('config', READ);

// Deletion of a history entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_run'])) {
    Session::checkRight('config', UPDATE);
    RunRepository::deleteRun((int) $_POST['delete_run']);
    Session::addMessageAfterRedirect(__('Diagnóstico removido do histórico.', 'healthcheck'), true, INFO);
    Html::redirect(Menu::getFrontUrl('history.php'));
}

$limit = 20;
$start = max(0, (int) ($_GET['start'] ?? 0));
$mode  = in_array($_GET['mode'] ?? '', [Runner::MODE_LIGHT, Runner::MODE_FULL], true) ? $_GET['mode'] : null;

$total = RunRepository::countRuns($mode);
$runs  = array_map([ReportView::class, 'decorateRun'], RunRepository::listRuns($start, $limit, $mode));

Page::header();

Page::render('history.html.twig', [
    'runs'       => $runs,
    'total'      => $total,
    'start'      => $start,
    'limit'      => $limit,
    'mode'       => $mode,
    'prev_start' => $start > 0 ? max(0, $start - $limit) : null,
    'next_start' => $start + $limit < $total ? $start + $limit : null,
    'trend'      => Page::trend(60),
]);

Page::footer();
