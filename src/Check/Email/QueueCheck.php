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
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\Format;
use GlpiPlugin\Healthcheck\Settings;
use QueuedNotification;

class QueueCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'email.queue';
    }

    public function getCategory(): string
    {
        return Category::EMAIL;
    }

    public function getTitle(): string
    {
        return __('Fila de notificações', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db    = $this->db();
        $table = QueuedNotification::getTable();
        $now   = date('Y-m-d H:i:s');

        $by_mode = [];
        $iterator = $db->request([
            'SELECT'  => [
                'mode',
                $this->expr('COUNT(*) AS nb'),
                $this->expr('SUM(CASE WHEN ' . $db->quoteName('sent_try') . ' > 0 THEN 1 ELSE 0 END) AS retried'),
                $this->expr('MIN(' . $db->quoteName('send_time') . ') AS oldest'),
            ],
            'FROM'    => $table,
            'WHERE'   => [
                'is_deleted' => 0,
                'send_time'  => ['<=', $now],
            ],
            'GROUPBY' => 'mode',
        ]);
        foreach ($iterator as $row) {
            $by_mode[$row['mode']] = $row;
        }

        $pending = (int) array_sum(array_column($by_mode, 'nb'));
        // All messages that already failed at least once, including those waiting for the retry delay
        $retried = $this->countRows($table, ['is_deleted' => 0, 'sent_try' => ['>', 0]]);
        // Messages GLPI gave up sending (deleted without sent date after max retries), last 7 days
        $abandoned = $this->countRows($table, [
            'is_deleted'  => 1,
            'sent_time'   => null,
            'sent_try'    => ['>', 0],
            'create_time' => ['>', date('Y-m-d H:i:s', $this->now() - WEEK_TIMESTAMP)],
        ]);
        $oldest  = null;
        foreach ($by_mode as $row) {
            if ($row['oldest'] !== null && ($oldest === null || strtotime($row['oldest']) < $oldest)) {
                $oldest = strtotime($row['oldest']);
            }
        }
        $oldest_age = $oldest !== null ? $this->now() - $oldest : 0;

        $sent_24h = $this->countRows($table, [
            'is_deleted' => 1,
            'sent_time'  => ['>', date('Y-m-d H:i:s', $this->now() - DAY_TIMESTAMP)],
        ]);
        $scheduled = $this->countRows($table, ['is_deleted' => 0, 'send_time' => ['>', $now]]);

        $facts = [
            __('Mensagens pendentes de envio', 'healthcheck')        => $pending,
            __('Pendentes com falha anterior (em retentativa)', 'healthcheck') => $retried,
            __('Rejeitadas definitivamente nos últimos 7 dias', 'healthcheck') => $abandoned,
            __('Agendadas para o futuro', 'healthcheck')             => $scheduled,
            __('Enviadas nas últimas 24h', 'healthcheck')            => $sent_24h,
            __('Pendente mais antiga', 'healthcheck')                => $oldest !== null ? date('Y-m-d H:i:s', $oldest) . ' (' . Format::duration($oldest_age) . ')' : '-',
        ];

        $items = [];
        foreach ($by_mode as $mode => $row) {
            $items[] = [
                'mode'    => $mode,
                'pending' => (int) $row['nb'],
                'retried' => (int) $row['retried'],
                'oldest'  => $row['oldest'],
            ];
        }
        $columns = [
            'mode'    => __('Canal', 'healthcheck'),
            'pending' => __('Pendentes', 'healthcheck'),
            'retried' => __('Em retentativa', 'healthcheck'),
            'oldest'  => __('Mais antiga', 'healthcheck'),
        ];

        $warning  = Settings::getInt('queue_warning_count');
        $critical = Settings::getInt('queue_critical_count');

        $findings = [];
        if ($pending >= $critical || $oldest_age > DAY_TIMESTAMP) {
            $findings[] = [Status::CRITICAL, Severity::HIGH];
        } elseif ($pending >= $warning || $oldest_age > HOUR_TIMESTAMP) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
        }
        if ($retried > 0) {
            $findings[] = [Status::WARNING, $retried >= 10 ? Severity::HIGH : Severity::MEDIUM];
        }
        if ($abandoned > 0) {
            $findings[] = [Status::WARNING, $abandoned >= 10 ? Severity::HIGH : Severity::MEDIUM];
        }

        if (count($findings) === 0) {
            return CheckResult::ok(
                sprintf(__('Fila de notificações saudável: %d mensagem(ns) pendente(s).', 'healthcheck'), $pending)
            )->facts($facts)->items($items, $columns);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(
                __('%1$d mensagem(ns) pendente(s) na fila, %2$d em retentativa e %3$d rejeitada(s) definitivamente em 7 dias; a mais antiga aguarda há %4$s.', 'healthcheck'),
                $pending,
                $retried,
                $abandoned,
                Format::duration($oldest_age)
            )
        )
            ->impact(__('Usuários e técnicos não recebem notificações de chamados no prazo.', 'healthcheck'))
            ->cause(__('Ação automática "queuednotification" parada ou atrasada, cron do sistema inativo, falha de autenticação/conexão SMTP ou volume de envio acima da capacidade.', 'healthcheck'))
            ->recommendation(__('Verifique o cron, a ação automática "queuednotification" e o log files/_log/mail-error.log.', 'healthcheck'))
            ->steps([
                __('Em Configurar > Ações automáticas, confirme que "queuednotification" está ativa e executando.', 'healthcheck'),
                __('Consulte files/_log/mail-error.log para ver o erro retornado pelo servidor SMTP.', 'healthcheck'),
                __('Envie um e-mail de teste em Configurar > Notificações > Configuração das notificações por e-mail.', 'healthcheck'),
                __('Se o volume for alto, aumente o parâmetro (quantidade por execução) da tarefa "queuednotification".', 'healthcheck'),
                __('Consulte as mensagens em Administração > Fila de notificações.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, $columns);
    }
}
