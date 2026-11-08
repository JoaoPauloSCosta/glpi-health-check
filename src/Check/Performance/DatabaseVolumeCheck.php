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

/**
 * Database size, biggest tables and fragmentation (information_schema only).
 */
class DatabaseVolumeCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'performance.db_volume';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Volume e fragmentação do banco', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db = $this->db();
        $tables = iterator_to_array($db->request([
            'SELECT' => ['TABLE_NAME', 'TABLE_ROWS', 'DATA_LENGTH', 'INDEX_LENGTH', 'DATA_FREE', 'ENGINE'],
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => [
                'TABLE_SCHEMA' => $db->dbdefault,
                'TABLE_TYPE'   => 'BASE TABLE',
            ],
        ]), false);

        $total_data = 0;
        $total_index = 0;
        $total_free = 0;
        foreach ($tables as &$table) {
            $table['size'] = (int) $table['DATA_LENGTH'] + (int) $table['INDEX_LENGTH'];
            $total_data  += (int) $table['DATA_LENGTH'];
            $total_index += (int) $table['INDEX_LENGTH'];
            $total_free  += (int) $table['DATA_FREE'];
        }
        unset($table);
        usort($tables, static fn ($a, $b) => $b['size'] <=> $a['size']);

        $items = [];
        $fragmented = [];
        foreach ($tables as $index => $table) {
            $free = (int) $table['DATA_FREE'];
            $is_fragmented = $free > 104857600 && $table['size'] > 0 && $free > $table['size'] * 0.3;
            if ($is_fragmented) {
                $fragmented[] = $table['TABLE_NAME'];
            }
            if ($index < 15 || $is_fragmented) {
                $items[] = [
                    'table' => $table['TABLE_NAME'],
                    'rows'  => Format::number((float) $table['TABLE_ROWS']),
                    'size'  => Format::bytes($table['size']),
                    'free'  => Format::bytes($free),
                ];
            }
        }

        $facts = [
            __('Tabelas', 'healthcheck')                     => count($tables),
            __('Dados', 'healthcheck')                       => Format::bytes($total_data),
            __('Índices', 'healthcheck')                     => Format::bytes($total_index),
            __('Espaço livre não recuperado', 'healthcheck') => Format::bytes($total_free),
        ];
        $columns = [
            'table' => __('Tabela', 'healthcheck'),
            'rows'  => __('Linhas (aprox.)', 'healthcheck'),
            'size'  => __('Tamanho', 'healthcheck'),
            'free'  => __('Espaço livre', 'healthcheck'),
        ];

        // Tables known to grow without control when cleanup tasks are not configured
        $growth = [];
        foreach ($tables as $table) {
            if (in_array($table['TABLE_NAME'], ['glpi_crontasklogs', 'glpi_events', 'glpi_queuednotifications', 'glpi_notimportedemails'], true)
                && (int) $table['TABLE_ROWS'] > 1000000) {
                $growth[] = sprintf('%s (%s)', $table['TABLE_NAME'], Format::number((float) $table['TABLE_ROWS']));
            }
        }

        $findings = [];
        if (count($fragmented) > 0) {
            $findings[] = [Status::WARNING, Severity::LOW];
        }
        if (count($growth) > 0) {
            $findings[] = [Status::WARNING, Severity::LOW];
        }

        if (count($findings) === 0) {
            return CheckResult::ok(sprintf(__('Banco com %s sem fragmentação relevante.', 'healthcheck'), Format::bytes($total_data + $total_index)))
                ->facts($facts)
                ->items($items, $columns);
        }

        $messages = [];
        if (count($fragmented) > 0) {
            $messages[] = sprintf(__('%d tabela(s) com fragmentação relevante.', 'healthcheck'), count($fragmented));
        }
        if (count($growth) > 0) {
            $messages[] = sprintf(__('Tabelas técnicas muito volumosas: %s.', 'healthcheck'), implode(', ', $growth));
        }

        return CheckResult::worstOf($findings, '', implode(' ', $messages))
            ->impact(__('Tabelas grandes e fragmentadas tornam backups, consultas e atualizações mais lentos.', 'healthcheck'))
            ->cause(__('Rotinas de limpeza desativadas ou tempo de retenção muito longo, e grandes exclusões sem reorganização das tabelas.', 'healthcheck'))
            ->recommendation(__('Reduza a retenção de logs técnicos e reorganize as tabelas fragmentadas em janela de manutenção.', 'healthcheck'))
            ->steps([
                __('Ajuste o tempo de retenção dos logs nas ações automáticas ("logs", "queuednotificationclean") e em Configurar > Geral > Logs.', 'healthcheck'),
                __('Após backup, execute "OPTIMIZE TABLE nome_da_tabela" para as tabelas fragmentadas (bloqueia a tabela durante a execução).', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, $columns);
    }
}
