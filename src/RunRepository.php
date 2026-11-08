<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use DBmysql;

/**
 * Persistence of diagnostic runs and their results (plugin tables only).
 */
final class RunRepository
{
    public const TABLE_RUNS    = 'glpi_plugin_healthcheck_runs';
    public const TABLE_RESULTS = 'glpi_plugin_healthcheck_results';

    private static function db(): DBmysql
    {
        /** @var DBmysql $DB */
        global $DB;
        return $DB;
    }

    /**
     * GLPI 10 DB layer expects pre-escaped string values.
     */
    private static function escapeRow(array $row): array
    {
        $db = self::db();
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $row[$key] = $db->escape($value);
            }
        }
        return $row;
    }

    public static function saveRun(array $run, array $results): int
    {
        $db = self::db();
        $db->insert(self::TABLE_RUNS, self::escapeRow($run));
        $run_id = (int) $db->insertId();
        if ($run_id <= 0) {
            throw new \RuntimeException('Unable to save diagnostic run: ' . $db->error());
        }
        foreach ($results as $result) {
            $result['plugin_healthcheck_runs_id'] = $run_id;
            $db->insert(self::TABLE_RESULTS, self::escapeRow($result));
        }
        return $run_id;
    }

    public static function getRun(int $id): ?array
    {
        $row = self::db()->request(['FROM' => self::TABLE_RUNS, 'WHERE' => ['id' => $id]])->current();
        return $row ? self::decodeRun($row) : null;
    }

    public static function getLastRun(?string $mode = null): ?array
    {
        $where = $mode !== null ? ['mode' => $mode] : [];
        $criteria = ['FROM' => self::TABLE_RUNS, 'ORDER' => 'id DESC', 'LIMIT' => 1];
        if (count($where) > 0) {
            $criteria['WHERE'] = $where;
        }
        $row = self::db()->request($criteria)->current();
        return $row ? self::decodeRun($row) : null;
    }

    /**
     * Previous run with the same mode (for comparison).
     */
    public static function getPreviousRun(array $run): ?array
    {
        $row = self::db()->request([
            'FROM'  => self::TABLE_RUNS,
            'WHERE' => ['id' => ['<', (int) $run['id']], 'mode' => $run['mode']],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();
        return $row ? self::decodeRun($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getResults(int $run_id): array
    {
        $results = [];
        foreach (self::db()->request([
            'FROM'  => self::TABLE_RESULTS,
            'WHERE' => ['plugin_healthcheck_runs_id' => $run_id],
            'ORDER' => 'id ASC',
        ]) as $row) {
            $details = json_decode((string) $row['details'], true);
            $row['details']  = is_array($details) ? $details : [];
            $row['severity'] = (int) $row['severity'];
            $results[$row['check_key']] = $row;
        }
        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listRuns(int $start = 0, int $limit = 20, ?string $mode = null): array
    {
        $criteria = [
            'FROM'  => self::TABLE_RUNS,
            'ORDER' => 'id DESC',
            'START' => max(0, $start),
            'LIMIT' => max(1, $limit),
        ];
        if ($mode !== null) {
            $criteria['WHERE'] = ['mode' => $mode];
        }
        $runs = [];
        foreach (self::db()->request($criteria) as $row) {
            $runs[] = self::decodeRun($row);
        }
        return $runs;
    }

    public static function countRuns(?string $mode = null): int
    {
        $criteria = ['COUNT' => 'cpt', 'FROM' => self::TABLE_RUNS];
        if ($mode !== null) {
            $criteria['WHERE'] = ['mode' => $mode];
        }
        return (int) (self::db()->request($criteria)->current()['cpt'] ?? 0);
    }

    public static function deleteRun(int $id): void
    {
        $db = self::db();
        $db->delete(self::TABLE_RESULTS, ['plugin_healthcheck_runs_id' => $id]);
        $db->delete(self::TABLE_RUNS, ['id' => $id]);
    }

    /**
     * Apply retention settings. Returns the number of deleted runs.
     */
    public static function purge(): int
    {
        $db = self::db();
        $ids = [];

        $retention = Settings::getInt('history_retention_days');
        if ($retention > 0) {
            foreach ($db->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABLE_RUNS,
                'WHERE'  => ['date_creation' => ['<', date('Y-m-d H:i:s', time() - $retention * DAY_TIMESTAMP)]],
            ]) as $row) {
                $ids[(int) $row['id']] = true;
            }
        }

        $max = Settings::getInt('history_max_runs');
        if ($max > 0) {
            foreach ($db->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABLE_RUNS,
                'ORDER'  => 'id DESC',
                'START'  => $max,
                'LIMIT'  => 100000,
            ]) as $row) {
                $ids[(int) $row['id']] = true;
            }
        }

        if (count($ids) === 0) {
            return 0;
        }
        $ids = array_keys($ids);
        foreach (array_chunk($ids, 500) as $chunk) {
            $db->delete(self::TABLE_RESULTS, ['plugin_healthcheck_runs_id' => $chunk]);
            $db->delete(self::TABLE_RUNS, ['id' => $chunk]);
        }
        return count($ids);
    }

    /**
     * Health score evolution, oldest first.
     *
     * @return array<int, array{id: int, date: string, score: int, overall: string, mode: string}>
     */
    public static function getTrend(int $limit = 30): array
    {
        $points = [];
        foreach (self::db()->request([
            'SELECT' => ['id', 'date_creation', 'score', 'overall', 'mode', 'nb_warning', 'nb_critical'],
            'FROM'   => self::TABLE_RUNS,
            'ORDER'  => 'id DESC',
            'LIMIT'  => $limit,
        ]) as $row) {
            $points[] = [
                'id'          => (int) $row['id'],
                'date'        => (string) $row['date_creation'],
                'score'       => (int) $row['score'],
                'overall'     => (string) $row['overall'],
                'mode'        => (string) $row['mode'],
                'nb_warning'  => (int) $row['nb_warning'],
                'nb_critical' => (int) $row['nb_critical'],
            ];
        }
        return array_reverse($points);
    }

    private static function decodeRun(array $row): array
    {
        $env = json_decode((string) ($row['environment'] ?? ''), true);
        $row['environment'] = is_array($env) ? $env : [];
        foreach (['id', 'users_id', 'score', 'nb_total', 'nb_ok', 'nb_warning', 'nb_critical', 'nb_info', 'nb_skipped', 'nb_error'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['duration'] = (float) $row['duration'];
        return $row;
    }
}
