<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Healthcheck\Cron;
use GlpiPlugin\Healthcheck\Menu;
use GlpiPlugin\Healthcheck\Page;
use GlpiPlugin\Healthcheck\Settings;

include('../../../inc/includes.php');

Session::checkRight('config', READ);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    Session::checkRight('config', UPDATE);
    $values = Settings::sanitizeInput($_POST);
    if (empty($values['allow_external_checks'])) {
        $values['release_cache'] = '';
    }
    Settings::set($values);
    Session::addMessageAfterRedirect(__('Configurações salvas.', 'healthcheck'), true, INFO);
    Html::redirect(Menu::getFrontUrl('config.php'));
}

// Automatic actions of the plugin (read only display, edited in GLPI's standard screen)
$tasks = [];
foreach ([Cron::TASK_LIGHT, Cron::TASK_FULL] as $name) {
    $task = new CronTask();
    if ($task->getFromDBbyName(Cron::class, $name)) {
        $tasks[] = [
            'name'      => $name,
            'label'     => Cron::cronInfo($name)['description'] ?? $name,
            'state'     => CronTask::getStateName($task->fields['state']),
            'mode'      => (int) $task->fields['mode'] === CronTask::MODE_EXTERNAL ? 'CLI' : 'GLPI',
            'frequency' => Html::timestampToString($task->fields['frequency'], false),
            'lastrun'   => $task->fields['lastrun'],
            'url'       => CronTask::getFormURLWithID($task->getID()),
        ];
    }
}

Page::header();
Page::render('config.html.twig', [
    'settings' => Settings::all(),
    'tasks'    => $tasks,
]);
Page::footer();
