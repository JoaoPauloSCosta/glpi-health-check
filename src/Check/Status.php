<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check;

/**
 * Possible outcomes of a check.
 */
final class Status
{
    /** Check passed. */
    public const OK       = 'ok';
    /** Attention required. */
    public const WARNING  = 'warning';
    /** Critical problem. */
    public const CRITICAL = 'critical';
    /** Informational result, not a problem. */
    public const INFO     = 'info';
    /** Check could not be performed (missing permission, out of context, disabled feature...). */
    public const SKIPPED  = 'skipped';
    /** Check crashed unexpectedly. */
    public const ERROR    = 'error';

    public const ALL = [self::CRITICAL, self::WARNING, self::ERROR, self::SKIPPED, self::INFO, self::OK];

    public static function getLabel(string $status): string
    {
        switch ($status) {
            case self::OK:
                return __('Aprovado', 'healthcheck');
            case self::WARNING:
                return __('Alerta', 'healthcheck');
            case self::CRITICAL:
                return __('Crítico', 'healthcheck');
            case self::INFO:
                return __('Informativo', 'healthcheck');
            case self::SKIPPED:
                return __('Não verificado', 'healthcheck');
            case self::ERROR:
                return __('Erro na verificação', 'healthcheck');
        }
        return $status;
    }

    /**
     * Bootstrap/Tabler color name.
     */
    public static function getColor(string $status): string
    {
        switch ($status) {
            case self::OK:
                return 'success';
            case self::WARNING:
                return 'warning';
            case self::CRITICAL:
                return 'danger';
            case self::INFO:
                return 'info';
            case self::ERROR:
                return 'dark';
        }
        return 'secondary';
    }

    public static function getIcon(string $status): string
    {
        switch ($status) {
            case self::OK:
                return 'ti ti-circle-check';
            case self::WARNING:
                return 'ti ti-alert-triangle';
            case self::CRITICAL:
                return 'ti ti-alert-octagon';
            case self::INFO:
                return 'ti ti-info-circle';
            case self::ERROR:
                return 'ti ti-bug';
        }
        return 'ti ti-circle-dashed';
    }

    /**
     * Sort weight (lower = displayed first).
     */
    public static function getWeight(string $status): int
    {
        $index = array_search($status, self::ALL, true);
        return $index === false ? 99 : (int) $index;
    }

    public static function isProblem(string $status): bool
    {
        return in_array($status, [self::WARNING, self::CRITICAL], true);
    }
}
