<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use CommonGLPI;
use CronTask;

/**
 * Automatic actions (Setup > Automatic actions).
 */
class Cron extends CommonGLPI
{
    public const TASK_LIGHT = 'HealthcheckLight';
    public const TASK_FULL  = 'HealthcheckFull';

    public static function getTypeName($nb = 0)
    {
        return __('Health Check', 'healthcheck');
    }

    /**
     * @param string $name
     * @return array
     */
    public static function cronInfo($name)
    {
        switch ($name) {
            case self::TASK_LIGHT:
                return [
                    'description' => __('Health Check: diagnóstico leve periódico', 'healthcheck'),
                ];
            case self::TASK_FULL:
                return [
                    'description' => __('Health Check: diagnóstico completo periódico', 'healthcheck'),
                ];
        }
        return [];
    }

    public static function cronHealthcheckLight(CronTask $task): int
    {
        return self::execute($task, Runner::MODE_LIGHT);
    }

    public static function cronHealthcheckFull(CronTask $task): int
    {
        return self::execute($task, Runner::MODE_FULL);
    }

    private static function execute(CronTask $task, string $mode): int
    {
        try {
            $run_id = (new Runner())->run($mode, Runner::TRIGGER_CRON, 0);
            $run = RunRepository::getRun($run_id);
            $task->addVolume(1);
            if ($run !== null) {
                $task->log(sprintf(
                    'Run #%d: score %d, %d critical, %d warning',
                    $run_id,
                    $run['score'],
                    $run['nb_critical'],
                    $run['nb_warning']
                ));
            }
            return 1;
        } catch (\Throwable $e) {
            $task->log('Health Check failed: ' . $e->getMessage());
            return -1;
        }
    }
}
