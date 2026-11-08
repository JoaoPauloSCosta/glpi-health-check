<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * Health indicator: 100 minus penalties of each problem, and global color.
 */
final class Score
{
    public const OVERALL_OK       = 'ok';       // green
    public const OVERALL_WARNING  = 'warning';  // yellow
    public const OVERALL_CRITICAL = 'critical'; // red

    private const PENALTIES = [
        Status::WARNING  => [Severity::LOW => 2, Severity::MEDIUM => 4, Severity::HIGH => 7],
        Status::CRITICAL => [Severity::HIGH => 12, Severity::CRITICAL => 20],
    ];

    public static function penalty(string $status, int $severity): int
    {
        return self::PENALTIES[$status][$severity] ?? 0;
    }

    /**
     * @param array<int, array{status: string, severity: int}> $results
     * @return array{score: int, overall: string, counts: array<string, int>}
     */
    public static function compute(array $results): array
    {
        $counts = array_fill_keys(Status::ALL, 0);
        $penalty = 0;
        foreach ($results as $result) {
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $penalty += self::penalty($result['status'], (int) $result['severity']);
        }
        $score = max(0, 100 - $penalty);

        if ($counts[Status::CRITICAL] > 0 || $score < 50) {
            $overall = self::OVERALL_CRITICAL;
        } elseif ($counts[Status::WARNING] > 0 || $score < 85) {
            $overall = self::OVERALL_WARNING;
        } else {
            $overall = self::OVERALL_OK;
        }

        return ['score' => $score, 'overall' => $overall, 'counts' => $counts];
    }

    public static function getOverallLabel(string $overall): string
    {
        switch ($overall) {
            case self::OVERALL_OK:
                return __('Ambiente saudável', 'healthcheck');
            case self::OVERALL_WARNING:
                return __('Atenção necessária', 'healthcheck');
            case self::OVERALL_CRITICAL:
                return __('Problema crítico', 'healthcheck');
        }
        return $overall;
    }

    public static function getOverallColor(string $overall): string
    {
        switch ($overall) {
            case self::OVERALL_OK:
                return 'success';
            case self::OVERALL_WARNING:
                return 'warning';
            case self::OVERALL_CRITICAL:
                return 'danger';
        }
        return 'secondary';
    }
}
