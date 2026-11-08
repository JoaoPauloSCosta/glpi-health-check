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
 * Criticity level of a problem.
 */
final class Severity
{
    public const NONE     = 0;
    public const LOW      = 1;
    public const MEDIUM   = 2;
    public const HIGH     = 3;
    public const CRITICAL = 4;

    public static function getLabel(int $severity): string
    {
        switch ($severity) {
            case self::LOW:
                return __('Baixa', 'healthcheck');
            case self::MEDIUM:
                return __('Média', 'healthcheck');
            case self::HIGH:
                return __('Alta', 'healthcheck');
            case self::CRITICAL:
                return __('Crítica', 'healthcheck');
        }
        return '-';
    }

    public static function getColor(int $severity): string
    {
        switch ($severity) {
            case self::LOW:
                return 'azure';
            case self::MEDIUM:
                return 'yellow';
            case self::HIGH:
                return 'orange';
            case self::CRITICAL:
                return 'red';
        }
        return 'secondary';
    }
}
