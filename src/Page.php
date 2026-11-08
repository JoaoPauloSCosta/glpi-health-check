<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Healthcheck\Check\Status;
use Html;
use Plugin;
use Session;

/**
 * Shared helpers of the plugin pages.
 */
final class Page
{
    public static function canRun(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function header(): void
    {
        Html::header(Menu::getMenuName(), $_SERVER['PHP_SELF'], 'config', Menu::class);
    }

    public static function footer(): void
    {
        Html::footer();
    }

    public static function urls(): array
    {
        return [
            'dashboard' => Menu::getFrontUrl('dashboard.php'),
            'history'   => Menu::getFrontUrl('history.php'),
            'config'    => Menu::getFrontUrl('config.php'),
            'run'       => Menu::getFrontUrl('run.php'),
            'compare'   => Menu::getFrontUrl('compare.php'),
            'export'    => Menu::getFrontUrl('export.php'),
        ];
    }

    public static function render(string $template, array $params = []): void
    {
        $status_labels = [];
        $status_colors = [];
        foreach (Status::ALL as $status) {
            $status_labels[$status] = Status::getLabel($status);
            $status_colors[$status] = Status::getColor($status);
        }
        TemplateRenderer::getInstance()->display('@healthcheck/' . $template, $params + [
            'urls'          => self::urls(),
            'can_run'       => self::canRun(),
            'status_labels' => $status_labels,
            'status_colors' => $status_colors,
            'change_labels' => ReportView::changeLabels(),
            'css_url'       => Plugin::getWebDir('healthcheck', true) . '/css/healthcheck.css?v='
                . (int) @filemtime(PLUGIN_HEALTHCHECK_DIR . '/css/healthcheck.css'),
        ]);
    }

    /**
     * SVG coordinates of the score evolution.
     */
    public static function trend(int $limit = 30, int $width = 600, int $height = 140): array
    {
        $pad    = 10;
        $points = RunRepository::getTrend($limit);
        $count  = count($points);

        $y = static fn (int $score): float => round($pad + ($height - 2 * $pad) * (1 - $score / 100), 1);
        $colors = [Score::OVERALL_OK => '#2fb344', Score::OVERALL_WARNING => '#f59f00', Score::OVERALL_CRITICAL => '#d63939'];

        $coords = [];
        foreach ($points as $index => &$point) {
            $point['x'] = $count > 1 ? round(30 + ($width - 40) * $index / ($count - 1), 1) : $width / 2;
            $point['y'] = $y($point['score']);
            $point['color'] = $colors[$point['overall']] ?? '#868e96';
            $point['mode'] = ReportView::modeLabel($point['mode']);
            $coords[] = $point['x'] . ',' . $point['y'];
        }
        unset($point);

        return [
            'width'    => $width,
            'height'   => $height,
            'points'   => $points,
            'polyline' => implode(' ', $coords),
            'grid'     => array_map(static fn ($v) => ['value' => $v, 'y' => $y($v)], [100, 85, 50]),
        ];
    }

    /**
     * Counters displayed on the dashboard.
     */
    public static function counters(array $run): array
    {
        return [
            ['label' => __('Aprovadas', 'healthcheck'), 'value' => $run['nb_ok'], 'color' => 'success', 'icon' => Status::getIcon(Status::OK)],
            ['label' => __('Alertas', 'healthcheck'), 'value' => $run['nb_warning'], 'color' => 'warning', 'icon' => Status::getIcon(Status::WARNING)],
            ['label' => __('Críticos', 'healthcheck'), 'value' => $run['nb_critical'], 'color' => 'danger', 'icon' => Status::getIcon(Status::CRITICAL)],
            ['label' => __('Informativos', 'healthcheck'), 'value' => $run['nb_info'], 'color' => 'info', 'icon' => Status::getIcon(Status::INFO)],
            ['label' => __('Não verificadas', 'healthcheck'), 'value' => $run['nb_skipped'] + $run['nb_error'], 'color' => 'secondary', 'icon' => Status::getIcon(Status::SKIPPED)],
        ];
    }
}
