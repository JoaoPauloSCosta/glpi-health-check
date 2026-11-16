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

// GLPI 10 runs this file directly and needs its bootstrap. Since GLPI 11 the kernel is already
// booted when the file is loaded, and including inc/includes.php is deprecated (GLPI 12).
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// CSRF protection of POST requests is enforced by GLPI itself (token up to GLPI 11, request headers since GLPI 12)
Session::checkRight('config', UPDATE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect(Menu::getFrontUrl('dashboard.php'));
}

$mode = ($_POST['mode'] ?? '') === Runner::MODE_LIGHT ? Runner::MODE_LIGHT : Runner::MODE_FULL;

// Release the session lock so the user can keep browsing GLPI while the diagnostic runs
session_write_close();

// Html::redirect() throws an exception since GLPI 11: it must stay outside the try block,
// otherwise a successful run would be reported as failed.
$run_id = null;
$error  = null;
try {
    $run_id = (new Runner())->run($mode, Runner::TRIGGER_MANUAL, (int) Session::getLoginUserID());
} catch (\Throwable $e) {
    $error = $e;
}

// Session was closed: re-open it to store the flash message
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if ($error !== null) {
    Toolbox::logInFile('php-errors', 'Health Check run failed: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    Session::addMessageAfterRedirect(
        sprintf(__('O diagnóstico não pôde ser concluído: %s', 'healthcheck'), $error->getMessage()),
        false,
        ERROR
    );
    Html::redirect(Menu::getFrontUrl('dashboard.php'));
}

Session::addMessageAfterRedirect(__('Diagnóstico concluído.', 'healthcheck'), true, INFO);
Html::redirect(Menu::getFrontUrl('dashboard.php', ['id' => $run_id]));
