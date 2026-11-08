<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Healthcheck\Cron;
use GlpiPlugin\Healthcheck\Settings;

/**
 * Plugin install process.
 *
 * Only creates the plugin's own tables, configuration entries and automatic actions.
 * Nothing belonging to GLPI itself is modified.
 *
 * @return boolean
 */
function plugin_healthcheck_install()
{
    /** @var \DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_HEALTHCHECK_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_healthcheck_runs')) {
        $query = "CREATE TABLE `glpi_plugin_healthcheck_runs` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_end` timestamp NULL DEFAULT NULL,
            `mode` varchar(10) NOT NULL DEFAULT 'full',
            `trigger_type` varchar(10) NOT NULL DEFAULT 'manual',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `glpi_version` varchar(50) DEFAULT NULL,
            `score` int NOT NULL DEFAULT '0',
            `overall` varchar(10) NOT NULL DEFAULT 'ok',
            `nb_total` int NOT NULL DEFAULT '0',
            `nb_ok` int NOT NULL DEFAULT '0',
            `nb_warning` int NOT NULL DEFAULT '0',
            `nb_critical` int NOT NULL DEFAULT '0',
            `nb_info` int NOT NULL DEFAULT '0',
            `nb_skipped` int NOT NULL DEFAULT '0',
            `nb_error` int NOT NULL DEFAULT '0',
            `duration` float NOT NULL DEFAULT '0',
            `environment` text,
            PRIMARY KEY (`id`),
            KEY `date_creation` (`date_creation`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQueryOrDie($query, 'Unable to create glpi_plugin_healthcheck_runs');
    }

    if (!$DB->tableExists('glpi_plugin_healthcheck_results')) {
        $query = "CREATE TABLE `glpi_plugin_healthcheck_results` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_healthcheck_runs_id` int {$sign} NOT NULL DEFAULT '0',
            `check_key` varchar(100) NOT NULL,
            `category` varchar(50) NOT NULL,
            `status` varchar(10) NOT NULL,
            `severity` int NOT NULL DEFAULT '0',
            `title` varchar(255) DEFAULT NULL,
            `message` text,
            `details` longtext,
            `duration` float NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `plugin_healthcheck_runs_id` (`plugin_healthcheck_runs_id`),
            KEY `check_key` (`check_key`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQueryOrDie($query, 'Unable to create glpi_plugin_healthcheck_results');
    }

    // Default configuration (existing values are kept on reinstall/upgrade)
    $current = Config::getConfigurationValues(Settings::CONTEXT);
    $missing = array_diff_key(Settings::DEFAULTS, $current);
    if (count($missing) > 0) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // Automatic actions
    CronTask::register(
        Cron::class,
        Cron::TASK_LIGHT,
        DAY_TIMESTAMP,
        [
            'comment' => 'Health Check - light diagnostic',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );
    CronTask::register(
        Cron::class,
        Cron::TASK_FULL,
        WEEK_TIMESTAMP,
        [
            'comment' => 'Health Check - full diagnostic',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
            'hourmin' => 1,
            'hourmax' => 6,
        ]
    );

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process.
 *
 * @return boolean
 */
function plugin_healthcheck_uninstall()
{
    /** @var \DBmysql $DB */
    global $DB;

    foreach (['glpi_plugin_healthcheck_results', 'glpi_plugin_healthcheck_runs'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `$table`", "Unable to drop $table");
        }
    }

    $config = new Config();
    $config->deleteByCriteria(['context' => Settings::CONTEXT]);

    CronTask::unregister('Healthcheck');

    return true;
}
