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

// GLPI 10 runs this file directly and needs its bootstrap. Since GLPI 11 the kernel is already
// booted when the file is loaded, and including inc/includes.php is deprecated (GLPI 12).
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

Session::checkRight('config', READ);

$a = RunRepository::getRun((int) ($_GET['a'] ?? 0));
$b = RunRepository::getRun((int) ($_GET['b'] ?? 0));

if ($a === null || $b === null || (int) $a['id'] === (int) $b['id']) {
    Session::addMessageAfterRedirect(__('Selecione dois diagnósticos diferentes para comparar.', 'healthcheck'), false, WARNING);
    Html::redirect(Menu::getFrontUrl('history.php'));
}

// Always compare older (base) -> newer
if ((int) $a['id'] > (int) $b['id']) {
    [$a, $b] = [$b, $a];
}

Page::header();

Page::render('compare.html.twig', [
    'old'  => ReportView::decorateRun($a),
    'new'  => ReportView::decorateRun($b),
    'diff' => ReportView::compare($a, $b),
]);

Page::footer();
