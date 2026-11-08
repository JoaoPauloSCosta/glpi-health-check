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
 * Database server performance indicators (SHOW GLOBAL STATUS / VARIABLES).
 */
class DatabaseServerCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'performance.db_server';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Indicadores de desempenho do banco', 'healthcheck');
    }

    public function run(): CheckResult
    {
        try {
            $status = $this->dbVariables('STATUS', [
                'Uptime', 'Questions', 'Slow_queries', 'Threads_connected', 'Max_used_connections',
                'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests', 'Created_tmp_disk_tables', 'Created_tmp_tables',
            ]);
            $vars = $this->dbVariables('VARIABLES', [
                'max_connections', 'innodb_buffer_pool_size', 'slow_query_log', 'long_query_time',
            ]);
        } catch (\Throwable $e) {
            return CheckResult::skipped(sprintf(__('Não foi possível ler os indicadores do servidor de banco: %s', 'healthcheck'), $e->getMessage()));
        }

        $uptime        = (int) ($status['uptime'] ?? 0);
        $questions     = (int) ($status['questions'] ?? 0);
        $slow          = (int) ($status['slow_queries'] ?? 0);
        $max_used      = (int) ($status['max_used_connections'] ?? 0);
        $max_conn      = (int) ($vars['max_connections'] ?? 0);
        $bp_size       = (int) ($vars['innodb_buffer_pool_size'] ?? 0);
        $bp_reads      = (int) ($status['innodb_buffer_pool_reads'] ?? 0);
        $bp_requests   = (int) ($status['innodb_buffer_pool_read_requests'] ?? 0);
        $tmp_disk      = (int) ($status['created_tmp_disk_tables'] ?? 0);
        $tmp_total     = (int) ($status['created_tmp_tables'] ?? 0);
        $hit_ratio     = $bp_requests > 0 ? 100 - Format::percent($bp_reads, $bp_requests) : null;

        // InnoDB data size of the GLPI database
        $row = $this->db()->request([
            'SELECT' => [$this->expr('SUM(' . $this->db()->quoteName('DATA_LENGTH') . ' + ' . $this->db()->quoteName('INDEX_LENGTH') . ') AS size')],
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => ['TABLE_SCHEMA' => $this->db()->dbdefault, 'ENGINE' => 'InnoDB'],
        ])->current();
        $innodb_size = (int) ($row['size'] ?? 0);

        $facts = [
            __('Tempo no ar', 'healthcheck')                         => Format::duration($uptime),
            __('Consultas lentas desde o início', 'healthcheck')     => Format::number($slow),
            __('Log de consultas lentas', 'healthcheck')             => ($vars['slow_query_log'] ?? 'OFF') . ' (long_query_time=' . ($vars['long_query_time'] ?? '?') . ')',
            __('Conexões: pico / máximo', 'healthcheck')             => $max_used . ' / ' . $max_conn,
            'innodb_buffer_pool_size'                                => Format::bytes($bp_size),
            __('Tamanho InnoDB da base GLPI', 'healthcheck')         => Format::bytes($innodb_size),
            __('Acertos no buffer pool', 'healthcheck')              => $hit_ratio !== null ? Format::number($hit_ratio, 2) . '%' : '-',
            __('Tabelas temporárias em disco', 'healthcheck')        => $tmp_total > 0 ? Format::number(Format::percent($tmp_disk, $tmp_total), 1) . '%' : '-',
        ];

        $items    = [];
        $findings = [];
        $add = function (string $indicator, string $value, string $advice, int $severity) use (&$items, &$findings) {
            $findings[] = [Status::WARNING, $severity];
            $items[] = ['indicator' => $indicator, 'value' => $value, 'advice' => $advice];
        };

        if ($max_conn > 0 && $max_used >= $max_conn * 0.85) {
            $add(__('Conexões', 'healthcheck'), $max_used . ' / ' . $max_conn, __('O pico de conexões chegou perto do limite: novas conexões podem ser recusadas ("Too many connections").', 'healthcheck'), Severity::MEDIUM);
        }
        if ($bp_size > 0 && $innodb_size > 0 && $bp_size < $innodb_size * 0.5 && $bp_size < 1073741824 * 8) {
            $add('innodb_buffer_pool_size', Format::bytes($bp_size), sprintf(__('Menor que metade dos dados InnoDB (%s): mais leituras em disco. Ajuste conforme a memória disponível do servidor.', 'healthcheck'), Format::bytes($innodb_size)), Severity::LOW);
        }
        if ($hit_ratio !== null && $uptime > HOUR_TIMESTAMP && $bp_requests > 100000 && $hit_ratio < 95) {
            $add(__('Acertos no buffer pool', 'healthcheck'), Format::number($hit_ratio, 2) . '%', __('Taxa abaixo de 95% indica buffer pool pequeno para o volume de dados.', 'healthcheck'), Severity::LOW);
        }
        if ($questions > 10000 && $slow > 0 && $slow / $questions > 0.01) {
            $add(__('Consultas lentas', 'healthcheck'), Format::number(Format::percent($slow, $questions), 2) . '%', __('Mais de 1% das consultas são lentas: analise o slow query log.', 'healthcheck'), Severity::LOW);
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('Os indicadores de desempenho do servidor de banco estão adequados.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf($findings, '', sprintf(__('%d indicador(es) de desempenho do banco requer(em) atenção.', 'healthcheck'), count($items)))
            ->impact(__('Banco subdimensionado deixa listas, buscas e painéis lentos e pode recusar conexões em horários de pico.', 'healthcheck'))
            ->cause(__('Configuração padrão do MySQL/MariaDB, crescimento da base ou consultas sem índice.', 'healthcheck'))
            ->recommendation(__('Ajuste os parâmetros do servidor de banco conforme a memória disponível e analise as consultas lentas.', 'healthcheck'))
            ->steps([
                __('Ative o slow query log (slow_query_log=ON, long_query_time=2) para identificar consultas lentas.', 'healthcheck'),
                __('Dimensione innodb_buffer_pool_size para conter a maior parte dos dados (até ~70% da RAM em servidor dedicado).', 'healthcheck'),
                __('Use ferramentas como MySQLTuner para uma análise mais detalhada.', 'healthcheck'),
            ])
            ->doc(self::DOC_PREREQUISITES)
            ->facts($facts)
            ->items($items, [
                'indicator' => __('Indicador', 'healthcheck'),
                'value'     => __('Valor', 'healthcheck'),
                'advice'    => __('Recomendação', 'healthcheck'),
            ]);
    }
}
