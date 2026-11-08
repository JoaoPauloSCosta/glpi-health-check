<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Email;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Format;
use GlpiPlugin\Healthcheck\Settings;
use MailCollector;

/**
 * TCP reachability of the SMTP server and active mail collectors' servers.
 * Only a TCP connection is opened: no message, login or password is sent.
 */
class ConnectivityCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'email.connectivity';
    }

    public function getCategory(): string
    {
        return Category::EMAIL;
    }

    public function getTitle(): string
    {
        return __('Conexão com servidores SMTP e IMAP/POP', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Abre apenas uma conexão TCP com os servidores configurados (sem autenticação e sem envio de dados).', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        if (!Settings::getInt('check_mail_connectivity')) {
            return CheckResult::skipped(__('Teste de conectividade com servidores de e-mail desativado nas configurações do plugin.', 'healthcheck'));
        }

        $targets = [];
        $mode = (int) $this->cfg('smtp_mode', MAIL_MAIL);
        if ($this->cfg('notifications_mailing', 0) && $mode !== MAIL_MAIL && trim((string) $this->cfg('smtp_host', '')) !== '') {
            $targets[] = [
                'service' => 'SMTP',
                'name'    => __('Envio de notificações', 'healthcheck'),
                'host'    => trim((string) $this->cfg('smtp_host')),
                'port'    => (int) $this->cfg('smtp_port', 25),
            ];
        }
        foreach ($this->db()->request([
            'SELECT' => ['name', 'host'],
            'FROM'   => MailCollector::getTable(),
            'WHERE'  => ['is_active' => 1],
        ]) as $collector) {
            $server = MailServerHelper::parse((string) $collector['host']);
            $targets[] = [
                'service' => strtoupper($server['type']),
                'name'    => $collector['name'],
                'host'    => $server['address'],
                'port'    => $server['port'],
            ];
        }

        if (count($targets) === 0) {
            return CheckResult::skipped(__('Nenhum servidor SMTP ou coletor de e-mail ativo para testar.', 'healthcheck'));
        }

        $items = [];
        $failures = 0;
        foreach ($targets as $target) {
            [$ok, $error, $elapsed] = MailServerHelper::tcpProbe($target['host'], $target['port']);
            if (!$ok) {
                $failures++;
            }
            $items[] = [
                'service' => $target['service'],
                'name'    => $target['name'],
                'server'  => $target['host'] . ':' . $target['port'],
                'result'  => $ok ? __('Acessível', 'healthcheck') : __('Inacessível', 'healthcheck'),
                'time'    => Format::number($elapsed, 0) . ' ms',
                'error'   => $error !== '' ? $error : '-',
            ];
        }
        $columns = [
            'service' => __('Serviço', 'healthcheck'),
            'name'    => __('Uso', 'healthcheck'),
            'server'  => __('Servidor', 'healthcheck'),
            'result'  => __('Resultado', 'healthcheck'),
            'time'    => __('Tempo', 'healthcheck'),
            'error'   => __('Erro', 'healthcheck'),
        ];

        if ($failures === 0) {
            return CheckResult::ok(sprintf(__('Todos os %d servidores de e-mail configurados estão acessíveis.', 'healthcheck'), count($targets)))
                ->items($items, $columns);
        }

        return CheckResult::critical(
            sprintf(__('%1$d de %2$d servidor(es) de e-mail inacessível(is) a partir do servidor do GLPI.', 'healthcheck'), $failures, count($targets)),
            Severity::HIGH
        )
            ->impact(__('Sem conexão não há envio de notificações nem coleta de e-mails.', 'healthcheck'))
            ->cause(__('Nome de servidor incorreto, falha de DNS, porta bloqueada por firewall/proxy, servidor fora do ar ou saída de rede bloqueada.', 'healthcheck'))
            ->recommendation(__('Teste a conexão diretamente do servidor do GLPI e libere a porta necessária.', 'healthcheck'))
            ->steps([
                __('No servidor do GLPI, teste a porta: "nc -vz servidor porta" (Linux) ou "Test-NetConnection servidor -Port porta" (Windows).', 'healthcheck'),
                __('Verifique a resolução DNS do nome do servidor.', 'healthcheck'),
                __('Solicite a liberação da porta no firewall de saída.', 'healthcheck'),
                __('Confirme servidor e porta na configuração do GLPI.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->items($items, $columns);
    }
}
