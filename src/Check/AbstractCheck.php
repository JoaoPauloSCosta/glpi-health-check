<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check;

use DBmysql;
use QueryExpression;

/**
 * Base class of every check.
 *
 * Rules every check must follow:
 *  - read-only: never modify files, database or configuration of GLPI;
 *  - independent: must not rely on another check's result;
 *  - fail gracefully: return CheckResult::skipped() when the check cannot be performed
 *    (missing permission, unavailable resource, out of context) instead of guessing.
 *
 * Third-party plugins can register their own checks, see Registry.
 */
abstract class AbstractCheck
{
    /** Official documentation links. */
    public const DOC_PREREQUISITES = 'https://glpi-install.readthedocs.io/en/latest/prerequisites.html';
    public const DOC_INSTALL       = 'https://glpi-install.readthedocs.io/en/latest/install/index.html';
    public const DOC_UPDATE        = 'https://glpi-install.readthedocs.io/en/latest/update.html';
    public const DOC_CLI           = 'https://glpi-install.readthedocs.io/en/latest/command-line.html';
    public const DOC_TIMEZONES     = 'https://glpi-install.readthedocs.io/en/latest/timezones.html';
    public const DOC_USER          = 'https://glpi-user-documentation.readthedocs.io/';
    public const DOC_PLUGINS       = 'https://glpi-plugins.readthedocs.io/';
    public const DOC_RELEASES      = 'https://github.com/glpi-project/glpi/releases';
    public const DOC_SECURITY      = 'https://github.com/glpi-project/glpi/security/advisories';
    public const DOC_PHP_VERSIONS  = 'https://www.php.net/supported-versions.php';

    /**
     * Unique identifier, e.g. "cron.last_execution".
     */
    abstract public function getKey(): string;

    /**
     * Category key, see Category constants.
     */
    abstract public function getCategory(): string;

    /**
     * Human readable name of the check.
     */
    abstract public function getTitle(): string;

    /**
     * Execute the check.
     */
    abstract public function run(): CheckResult;

    /**
     * Short explanation of what is verified.
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * Light checks are cheap and run in the frequent automatic diagnostic.
     * Heavy checks (schema comparison, data scans...) only run in full diagnostics.
     */
    public function isLight(): bool
    {
        return true;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function db(): DBmysql
    {
        /** @var DBmysql $DB */
        global $DB;
        return $DB;
    }

    /**
     * @return mixed
     */
    protected function cfg(string $name, $default = null)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        return $CFG_GLPI[$name] ?? $default;
    }

    protected function countRows(string $table, array $where = []): int
    {
        $criteria = [
            'COUNT' => 'cpt',
            'FROM'  => $table,
        ];
        if (count($where) > 0) {
            $criteria['WHERE'] = $where;
        }
        $row = $this->db()->request($criteria)->current();
        return (int) ($row['cpt'] ?? 0);
    }

    protected function tableExists(string $table): bool
    {
        return $this->db()->tableExists($table, false);
    }

    protected function fieldExists(string $table, string $field): bool
    {
        return $this->db()->fieldExists($table, $field, false);
    }

    /**
     * Run a raw read-only query (SHOW, SELECT) and return all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function rawRows(string $query): array
    {
        $result = $this->db()->doQuery($query);
        if ($result === false || $result === true) {
            throw new \RuntimeException($this->db()->error());
        }
        $rows = [];
        while ($row = $this->db()->fetchAssoc($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * SHOW GLOBAL VARIABLES / STATUS as key => value.
     *
     * @param string[] $names
     * @return array<string, string>
     */
    protected function dbVariables(string $kind, array $names): array
    {
        $kind = $kind === 'STATUS' ? 'STATUS' : 'VARIABLES';
        $list = implode(', ', array_map(fn ($n) => $this->db()->quote($n), $names));
        $values = [];
        foreach ($this->rawRows("SHOW GLOBAL $kind WHERE Variable_name IN ($list)") as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $values[strtolower($row['variable_name'])] = (string) $row['value'];
        }
        return $values;
    }

    protected function now(): int
    {
        return time();
    }

    protected function expr(string $sql): QueryExpression
    {
        return new QueryExpression($sql);
    }

    protected function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    protected function cliNotice(): string
    {
        return __('Esta verificação depende do contexto web e não pode ser avaliada quando o diagnóstico é executado pelo cron (CLI). Execute o diagnóstico manualmente pelo navegador.', 'healthcheck');
    }
}
