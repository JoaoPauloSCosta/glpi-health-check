<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

final class Format
{
    public static function bytes(float $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while (abs($bytes) >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $i === 0 ? 0 : $precision, ',', '.') . ' ' . $units[$i];
    }

    public static function number(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf(__('%d s', 'healthcheck'), $seconds);
        }
        if ($seconds < 3600) {
            return sprintf(__('%d min', 'healthcheck'), intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return sprintf(__('%1$d h %2$d min', 'healthcheck'), intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }
        return sprintf(__('%1$d dia(s) %2$d h', 'healthcheck'), intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }

    /**
     * Parse php.ini shorthand byte values ("128M", "1G", "-1").
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        switch ($unit) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
        }
        return (int) $number;
    }

    public static function percent(float $part, float $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
    }
}
