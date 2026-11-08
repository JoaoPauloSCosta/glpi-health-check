<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Cron\AbstractCronCheck;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * Executes the checks, isolating each one, and stores the run.
 */
final class Runner
{
    public const MODE_LIGHT = 'light';
    public const MODE_FULL  = 'full';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_CRON   = 'cron';

    /**
     * @return int id of the stored run
     */
    public function run(string $mode = self::MODE_FULL, string $trigger = self::TRIGGER_MANUAL, int $users_id = 0): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $mode = $mode === self::MODE_LIGHT ? self::MODE_LIGHT : self::MODE_FULL;
        if ($mode === self::MODE_FULL && function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        AbstractCronCheck::resetCache();

        $start_date = date('Y-m-d H:i:s');
        $start      = microtime(true);

        $registry_errors = [];
        $checks = Registry::getChecks($registry_errors);

        $results = [];
        foreach ($checks as $check) {
            if ($mode === self::MODE_LIGHT && !$check->isLight()) {
                continue;
            }
            $results[] = $this->runCheck($check);
        }
        foreach ($registry_errors as $error) {
            $results[] = [
                'check_key' => 'registry.' . substr(md5($error['class']), 0, 8),
                'category'  => 'plugins',
                'status'    => Status::ERROR,
                'severity'  => 0,
                'title'     => sprintf(__('Verificação externa inválida: %s', 'healthcheck'), $error['class']),
                'message'   => $error['error'],
                'details'   => json_encode([]),
                'duration'  => 0,
            ];
        }

        $score = Score::compute($results);

        $run = [
            'date_creation' => $start_date,
            'date_end'      => date('Y-m-d H:i:s'),
            'mode'          => $mode,
            'trigger_type'  => $trigger === self::TRIGGER_CRON ? self::TRIGGER_CRON : self::TRIGGER_MANUAL,
            'users_id'      => max(0, $users_id),
            'glpi_version'  => GLPI_VERSION,
            'score'         => $score['score'],
            'overall'       => $score['overall'],
            'nb_total'      => count($results),
            'nb_ok'         => $score['counts'][Status::OK],
            'nb_warning'    => $score['counts'][Status::WARNING],
            'nb_critical'   => $score['counts'][Status::CRITICAL],
            'nb_info'       => $score['counts'][Status::INFO],
            'nb_skipped'    => $score['counts'][Status::SKIPPED],
            'nb_error'      => $score['counts'][Status::ERROR],
            'duration'      => round(microtime(true) - $start, 3),
            'environment'   => json_encode(self::getEnvironment($mode), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $run_id = RunRepository::saveRun($run, $results);
        RunRepository::purge();

        return $run_id;
    }

    /**
     * Run a single check, never letting an exception or unexpected output escape.
     */
    private function runCheck(AbstractCheck $check): array
    {
        $start = microtime(true);
        ob_start();
        try {
            $result = $check->run();
        } catch (\Throwable $e) {
            $result = CheckResult::error(
                sprintf(__('A verificação não pôde ser concluída: %s', 'healthcheck'), $e->getMessage())
            )->recommendation(__('Verifique o log files/_log/php-errors.log. As demais verificações não foram afetadas.', 'healthcheck'));
            \Toolbox::logInFile(
                'php-errors',
                sprintf("Health Check: check \"%s\" failed: %s\n%s\n", $check->getKey(), $e->getMessage(), $e->getTraceAsString())
            );
        } finally {
            ob_end_clean();
        }

        $data = $result->toArray();
        $data['description'] = $check->getDescription();
        $data['light']       = $check->isLight();

        $status   = $data['status'];
        $severity = $data['severity'];
        $message  = $data['message'];
        unset($data['status'], $data['severity'], $data['message']);

        return [
            'check_key' => $check->getKey(),
            'category'  => $check->getCategory(),
            'status'    => $status,
            'severity'  => $severity,
            'title'     => mb_substr($check->getTitle(), 0, 255),
            'message'   => $message,
            'details'   => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'duration'  => round(microtime(true) - $start, 4),
        ];
    }

    /**
     * Identification of the analysed environment (stored locally only).
     */
    public static function getEnvironment(string $mode): array
    {
        /** @var \DBmysql $DB */
        global $DB, $CFG_GLPI;

        return [
            'plugin_version' => PLUGIN_HEALTHCHECK_VERSION,
            'glpi_version'   => GLPI_VERSION,
            'php_version'    => PHP_VERSION,
            'php_sapi'       => PHP_SAPI,
            'os'             => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'hostname'       => (string) gethostname(),
            'db_version'     => (string) $DB->getVersion(),
            'url_base'       => (string) ($CFG_GLPI['url_base'] ?? ''),
            'mode'           => $mode,
            'external'       => Settings::allowExternal(),
        ];
    }
}
