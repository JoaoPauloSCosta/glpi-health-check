<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Performance;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\Format;

class MemoryCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'performance.memory';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Uso de memória', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $limit = Format::iniBytes((string) ini_get('memory_limit'));
        $peak  = memory_get_peak_usage(true);

        $facts = [
            'memory_limit'                                  => $limit < 0 ? __('Ilimitado', 'healthcheck') : Format::bytes($limit),
            __('Pico desta execução', 'healthcheck')        => Format::bytes($peak),
        ];

        $findings = [];
        $items    = [];

        if ($limit > 0 && $limit < 256 * 1024 * 1024) {
            $findings[] = [Status::WARNING, $limit < 128 * 1024 * 1024 ? Severity::MEDIUM : Severity::LOW];
            $items[] = [
                'indicator' => 'memory_limit',
                'value'     => Format::bytes($limit),
                'advice'    => __('Recomendado pelo menos 256 MB para relatórios, exportações, inventário e ações em massa.', 'healthcheck'),
            ];
        }

        // System memory (Linux only, when /proc is readable)
        $meminfo = @is_readable('/proc/meminfo') ? @file_get_contents('/proc/meminfo') : false;
        if (is_string($meminfo)
            && preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total) === 1
            && preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available) === 1) {
            $total_b     = (int) $total[1] * 1024;
            $available_b = (int) $available[1] * 1024;
            $pct = Format::percent($available_b, $total_b);
            $facts[__('Memória do servidor', 'healthcheck')]        = Format::bytes($total_b);
            $facts[__('Memória disponível', 'healthcheck')]         = Format::bytes($available_b) . ' (' . Format::number($pct, 1) . '%)';
            if ($pct < 10) {
                $findings[] = [Status::WARNING, $pct < 5 ? Severity::HIGH : Severity::MEDIUM];
                $items[] = [
                    'indicator' => __('Memória disponível no servidor', 'healthcheck'),
                    'value'     => Format::number($pct, 1) . '%',
                    'advice'    => __('Pouca memória livre: o sistema pode usar swap, ficando lento, ou encerrar processos do banco/PHP.', 'healthcheck'),
                ];
            }
        } else {
            $facts[__('Memória do servidor', 'healthcheck')] = __('Não acessível', 'healthcheck');
        }

        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load)) {
                $facts[__('Carga média (1/5/15 min)', 'healthcheck')] = implode(' / ', array_map(static fn ($l) => Format::number($l, 2), $load));
            }
        }

        if (count($findings) === 0) {
            return CheckResult::ok(__('Os indicadores de memória estão adequados.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf($findings, '', __('Indicadores de memória requerem atenção.', 'healthcheck'))
            ->impact(__('Falta de memória causa erros "Allowed memory size exhausted", páginas em branco e lentidão.', 'healthcheck'))
            ->cause(__('Limite de memória do PHP baixo ou servidor subdimensionado para a carga.', 'healthcheck'))
            ->recommendation(__('Ajuste memory_limit no php.ini (web e CLI) e avalie aumentar a memória do servidor.', 'healthcheck'))
            ->doc(self::DOC_PREREQUISITES)
            ->facts($facts)
            ->items($items, [
                'indicator' => __('Indicador', 'healthcheck'),
                'value'     => __('Valor', 'healthcheck'),
                'advice'    => __('Recomendação', 'healthcheck'),
            ]);
    }
}
