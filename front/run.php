<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Healthcheck\Menu;
use GlpiPlugin\Healthcheck\Runner;

include('../../../inc/includes.php');

// CSRF token is checked by GLPI for POST requests (plugin is csrf_compliant)
Session::checkRight('config', UPDATE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect(Menu::getFrontUrl('dashboard.php'));
}

$mode = ($_POST['mode'] ?? '') === Runner::MODE_LIGHT ? Runner::MODE_LIGHT : Runner::MODE_FULL;

// Release the session lock so the user can keep browsing GLPI while the diagnostic runs
session_write_close();

try {
    $run_id = (new Runner())->run($mode, Runner::TRIGGER_MANUAL, (int) Session::getLoginUserID());
    // Session was closed: re-open it to store the flash message
    @session_start();
    Session::addMessageAfterRedirect(__('Diagnóstico concluído.', 'healthcheck'), true, INFO);
    Html::redirect(Menu::getFrontUrl('dashboard.php', ['id' => $run_id]));
} catch (\Throwable $e) {
    @session_start();
    Toolbox::logInFile('php-errors', 'Health Check run failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    Session::addMessageAfterRedirect(
        sprintf(__('O diagnóstico não pôde ser concluído: %s', 'healthcheck'), $e->getMessage()),
        false,
        ERROR
    );
    Html::redirect(Menu::getFrontUrl('dashboard.php'));
}
