<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use Config;

/**
 * Plugin settings, stored in glpi_configs under the "plugin:healthcheck" context.
 */
final class Settings
{
    public const CONTEXT = 'plugin:healthcheck';

    public const DEFAULTS = [
        // Optional external lookups (GLPI releases on GitHub, plugins marketplace). Disabled by default.
        'allow_external_checks'      => '0',
        // TCP reachability test of the configured SMTP/IMAP servers (full diagnostic only).
        'check_mail_connectivity'    => '1',
        // History retention
        'history_retention_days'     => '180',
        'history_max_runs'           => '500',
        // Thresholds
        'disk_warning_percent'       => '15',
        'disk_critical_percent'      => '5',
        'queue_warning_count'        => '100',
        'queue_critical_count'       => '1000',
        // Internal cache of the last external release lookup
        'release_cache'              => '',
    ];

    /** @var array<string, string>|null */
    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values === null) {
            $stored = Config::getConfigurationValues(self::CONTEXT);
            self::$values = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        }
        return self::$values;
    }

    /**
     * @return mixed
     */
    public static function get(string $name)
    {
        return self::all()[$name] ?? (self::DEFAULTS[$name] ?? null);
    }

    public static function getInt(string $name): int
    {
        return (int) self::get($name);
    }

    public static function allowExternal(): bool
    {
        return (bool) self::getInt('allow_external_checks');
    }

    public static function set(array $values): void
    {
        $values = array_intersect_key($values, self::DEFAULTS);
        if (count($values) === 0) {
            return;
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
        self::$values = null;
    }

    /**
     * Sanitize values coming from the configuration form.
     */
    public static function sanitizeInput(array $input): array
    {
        $clean = [];
        foreach (['allow_external_checks', 'check_mail_connectivity'] as $bool) {
            $clean[$bool] = !empty($input[$bool]) ? '1' : '0';
        }
        $ranges = [
            'history_retention_days' => [1, 3650],
            'history_max_runs'       => [10, 100000],
            'disk_warning_percent'   => [1, 90],
            'disk_critical_percent'  => [1, 90],
            'queue_warning_count'    => [1, 1000000],
            'queue_critical_count'   => [1, 10000000],
        ];
        foreach ($ranges as $name => [$min, $max]) {
            if (isset($input[$name]) && is_numeric($input[$name])) {
                $clean[$name] = (string) max($min, min($max, (int) $input[$name]));
            }
        }
        if (isset($clean['disk_warning_percent'], $clean['disk_critical_percent'])
            && (int) $clean['disk_critical_percent'] > (int) $clean['disk_warning_percent']) {
            $clean['disk_critical_percent'] = $clean['disk_warning_percent'];
        }
        if (isset($clean['queue_warning_count'], $clean['queue_critical_count'])
            && (int) $clean['queue_critical_count'] < (int) $clean['queue_warning_count']) {
            $clean['queue_critical_count'] = $clean['queue_warning_count'];
        }
        return $clean;
    }
}
