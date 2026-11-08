<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use User;

/**
 * Prepares stored data for templates and exports.
 */
final class ReportView
{
    public static function modeLabel(string $mode): string
    {
        return $mode === Runner::MODE_LIGHT ? __('Leve', 'healthcheck') : __('Completo', 'healthcheck');
    }

    public static function triggerLabel(string $trigger): string
    {
        return $trigger === Runner::TRIGGER_CRON ? __('Automático (cron)', 'healthcheck') : __('Manual', 'healthcheck');
    }

    /**
     * Most severe first, then by category order.
     */
    public static function sortResults(array $results): array
    {
        $category_order = array_flip(array_keys(Category::all()));
        uasort($results, static function ($a, $b) use ($category_order) {
            return [Status::getWeight($a['status']), -$a['severity'], $category_order[$a['category']] ?? 99]
                <=> [Status::getWeight($b['status']), -$b['severity'], $category_order[$b['category']] ?? 99];
        });
        return $results;
    }

    /**
     * Decorate a stored result with display attributes.
     */
    public static function decorate(array $result): array
    {
        $result['status_label']   = Status::getLabel($result['status']);
        $result['status_color']   = Status::getColor($result['status']);
        $result['status_icon']    = Status::getIcon($result['status']);
        $result['severity_label'] = Severity::getLabel((int) $result['severity']);
        $result['severity_color'] = Severity::getColor((int) $result['severity']);
        $result['category_label'] = Category::getLabel($result['category']);
        $result['penalty']        = Score::penalty($result['status'], (int) $result['severity']);
        $result['is_problem']     = Status::isProblem($result['status']);
        return $result;
    }

    public static function decorateRun(array $run): array
    {
        $run['overall_label'] = Score::getOverallLabel($run['overall']);
        $run['overall_color'] = Score::getOverallColor($run['overall']);
        $run['mode_label']    = self::modeLabel($run['mode']);
        $run['trigger_label'] = self::triggerLabel($run['trigger_type']);
        $run['user_name']     = $run['users_id'] > 0 ? getUserName($run['users_id']) : '-';
        return $run;
    }

    /**
     * @return array{run: array, problems: array, categories: array, previous: ?array, diff: ?array}
     */
    public static function buildDashboard(array $run): array
    {
        $results = array_map([self::class, 'decorate'], self::sortResults(RunRepository::getResults($run['id'])));

        $problems = array_filter($results, static fn ($r) => $r['is_problem']);

        $categories = [];
        foreach (Category::all() as $key => $category) {
            $categories[$key] = $category + ['key' => $key, 'results' => [], 'counts' => array_fill_keys(Status::ALL, 0), 'worst' => Status::OK];
        }
        foreach ($results as $result) {
            $key = $result['category'];
            if (!isset($categories[$key])) {
                $categories[$key] = ['key' => $key, 'label' => $key, 'icon' => 'ti ti-list-check', 'results' => [], 'counts' => array_fill_keys(Status::ALL, 0), 'worst' => Status::OK];
            }
            $categories[$key]['results'][] = $result;
            $categories[$key]['counts'][$result['status']]++;
            if (Status::getWeight($result['status']) < Status::getWeight($categories[$key]['worst'])) {
                $categories[$key]['worst'] = $result['status'];
            }
        }
        $categories = array_filter($categories, static fn ($c) => count($c['results']) > 0);
        foreach ($categories as &$category) {
            $category['worst_color'] = Status::getColor($category['worst']);
            $category['worst_icon']  = Status::getIcon($category['worst']);
        }
        unset($category);

        $previous = RunRepository::getPreviousRun($run);
        $diff = $previous !== null ? self::compare($previous, $run) : null;

        return [
            'run'        => self::decorateRun($run),
            'problems'   => array_values($problems),
            'categories' => array_values($categories),
            'previous'   => $previous !== null ? self::decorateRun($previous) : null,
            'diff'       => $diff,
        ];
    }

    /**
     * Compare two runs, check by check.
     *
     * @return array{rows: array, new: int, resolved: int, worse: int, better: int, unchanged: int}
     */
    public static function compare(array $old_run, array $new_run): array
    {
        $old = RunRepository::getResults($old_run['id']);
        $new = RunRepository::getResults($new_run['id']);

        $rows = [];
        $summary = ['new' => 0, 'resolved' => 0, 'worse' => 0, 'better' => 0, 'unchanged' => 0];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $a = $old[$key] ?? null;
            $b = $new[$key] ?? null;

            $old_penalty = $a !== null ? Score::penalty($a['status'], (int) $a['severity']) : 0;
            $new_penalty = $b !== null ? Score::penalty($b['status'], (int) $b['severity']) : 0;
            $old_problem = $a !== null && Status::isProblem($a['status']);
            $new_problem = $b !== null && Status::isProblem($b['status']);

            if (!$old_problem && $new_problem) {
                $change = 'new';
            } elseif ($old_problem && !$new_problem && $b !== null) {
                $change = 'resolved';
            } elseif ($new_penalty > $old_penalty) {
                $change = 'worse';
            } elseif ($new_penalty < $old_penalty) {
                $change = 'better';
            } else {
                $change = 'unchanged';
            }
            $summary[$change]++;

            $ref = $b ?? $a;
            $rows[] = [
                'key'          => $key,
                'title'        => $ref['title'],
                'category'     => Category::getLabel($ref['category']),
                'old_status'   => $a !== null ? Status::getLabel($a['status']) : '-',
                'old_color'    => $a !== null ? Status::getColor($a['status']) : 'secondary',
                'old_severity' => $a !== null ? Severity::getLabel((int) $a['severity']) : '-',
                'new_status'   => $b !== null ? Status::getLabel($b['status']) : __('Não executada', 'healthcheck'),
                'new_color'    => $b !== null ? Status::getColor($b['status']) : 'secondary',
                'new_severity' => $b !== null ? Severity::getLabel((int) $b['severity']) : '-',
                'new_message'  => $b['message'] ?? '',
                'change'       => $change,
            ];
        }

        $order = ['new' => 0, 'worse' => 1, 'resolved' => 2, 'better' => 3, 'unchanged' => 4];
        usort($rows, static fn ($x, $y) => $order[$x['change']] <=> $order[$y['change']]);

        return ['rows' => $rows] + $summary;
    }

    public static function changeLabels(): array
    {
        return [
            'new'       => ['label' => __('Novo problema', 'healthcheck'), 'color' => 'danger', 'icon' => 'ti ti-arrow-down-right'],
            'worse'     => ['label' => __('Piorou', 'healthcheck'), 'color' => 'orange', 'icon' => 'ti ti-trending-down'],
            'resolved'  => ['label' => __('Resolvido', 'healthcheck'), 'color' => 'success', 'icon' => 'ti ti-check'],
            'better'    => ['label' => __('Melhorou', 'healthcheck'), 'color' => 'teal', 'icon' => 'ti ti-trending-up'],
            'unchanged' => ['label' => __('Sem alteração', 'healthcheck'), 'color' => 'secondary', 'icon' => 'ti ti-equal'],
        ];
    }
}
