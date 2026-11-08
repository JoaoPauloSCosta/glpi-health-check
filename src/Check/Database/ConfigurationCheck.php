<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Database;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\Format;

class ConfigurationCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'database.configuration';
    }

    public function getCategory(): string
    {
        return Category::DATABASE;
    }

    public function getTitle(): string
    {
        return __('Configurações críticas do banco', 'healthcheck');
    }

    public function run(): CheckResult
    {
        try {
            $vars = $this->dbVariables('VARIABLES', [
                'max_allowed_packet',
                'character_set_server',
                'collation_server',
                'innodb_file_per_table',
                'wait_timeout',
                'max_connections',
            ]);
        } catch (\Throwable $e) {
            return CheckResult::skipped(
                sprintf(__('Não foi possível ler as variáveis do servidor de banco: %s', 'healthcheck'), $e->getMessage())
            );
        }

        $items    = [];
        $findings = [];

        $packet = (int) ($vars['max_allowed_packet'] ?? 0);
        if ($packet > 0 && $packet < 16 * 1024 * 1024) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
            $items[] = [
                'setting' => 'max_allowed_packet',
                'value'   => Format::bytes($packet),
                'advice'  => __('Recomendado pelo menos 16 MB (64 MB ou mais se documentos forem grandes). Valores baixos causam falhas ao salvar textos longos e na importação de e-mails.', 'healthcheck'),
            ];
        }

        $charset = (string) ($vars['character_set_server'] ?? '');
        if ($charset !== '' && $charset !== 'utf8mb4') {
            $findings[] = [Status::WARNING, Severity::LOW];
            $items[] = [
                'setting' => 'character_set_server',
                'value'   => $charset,
                'advice'  => __('Recomendado utf8mb4 para que novas tabelas (incluindo de plugins) suportem todos os caracteres.', 'healthcheck'),
            ];
        }

        if (isset($vars['innodb_file_per_table']) && strtoupper($vars['innodb_file_per_table']) === 'OFF') {
            $findings[] = [Status::WARNING, Severity::LOW];
            $items[] = [
                'setting' => 'innodb_file_per_table',
                'value'   => 'OFF',
                'advice'  => __('Com OFF, o espaço liberado por limpezas nunca é devolvido ao disco. Recomendado ON.', 'healthcheck'),
            ];
        }

        $wait = (int) ($vars['wait_timeout'] ?? 0);
        if ($wait > 0 && $wait < 60) {
            $findings[] = [Status::WARNING, Severity::LOW];
            $items[] = [
                'setting' => 'wait_timeout',
                'value'   => $wait . ' s',
                'advice'  => __('Valores muito baixos derrubam conexões de tarefas longas (cron, coleta de e-mails).', 'healthcheck'),
            ];
        }

        // Clock drift between PHP and database server
        $rows  = $this->rawRows('SELECT UNIX_TIMESTAMP() AS ts');
        $drift = abs((int) ($rows[0]['ts'] ?? time()) - time());
        if ($drift > 60) {
            $findings[] = [Status::WARNING, $drift > 600 ? Severity::HIGH : Severity::MEDIUM];
            $items[] = [
                'setting' => __('Diferença de relógio PHP x banco', 'healthcheck'),
                'value'   => $drift . ' s',
                'advice'  => __('Relógios dessincronizados afetam SLAs, ações automáticas e datas de chamados. Configure NTP nos dois servidores.', 'healthcheck'),
            ];
        }

        $facts = [
            'max_allowed_packet'   => $packet > 0 ? Format::bytes($packet) : null,
            'character_set_server' => $vars['character_set_server'] ?? null,
            'collation_server'     => $vars['collation_server'] ?? null,
            'max_connections'      => $vars['max_connections'] ?? null,
            __('Diferença de relógio (s)', 'healthcheck') => $drift,
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('As configurações críticas do servidor de banco estão adequadas.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d configuração(ões) do servidor de banco requer(em) atenção.', 'healthcheck'), count($items))
        )
            ->impact(__('Configurações inadequadas do banco causam falhas intermitentes e problemas de desempenho.', 'healthcheck'))
            ->cause(__('Valores padrão do pacote MySQL/MariaDB ou ajustes não revisados.', 'healthcheck'))
            ->recommendation(__('Ajuste os parâmetros no arquivo de configuração do servidor de banco (my.cnf / server.cnf) e reinicie o serviço em janela de manutenção.', 'healthcheck'))
            ->doc(self::DOC_PREREQUISITES)
            ->facts($facts)
            ->items($items, [
                'setting' => __('Parâmetro', 'healthcheck'),
                'value'   => __('Valor atual', 'healthcheck'),
                'advice'  => __('Recomendação', 'healthcheck'),
            ]);
    }
}
