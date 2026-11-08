<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Cron;

use CronTaskLog;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Format;

class FailuresCheck extends AbstractCronCheck
{
    private const PERIOD_DAYS = 7;

    public function getKey(): string
    {
        return 'cron.failures';
    }

    public function getTitle(): string
    {
        return __('Tarefas com falhas recorrentes', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db    = $this->db();
        $since = date('Y-m-d H:i:s', $this->now() - self::PERIOD_DAYS * DAY_TIMESTAMP);
        $tasks = $this->getTasks();

        $stats = [];
        $iterator = $db->request([
            'SELECT'  => [
                'crontasks_id',
                'state',
                $this->expr('COUNT(*) AS nb'),
                $this->expr('MAX(' . $db->quoteName('date') . ') AS last_date'),
            ],
            'FROM'    => CronTaskLog::getTable(),
            'WHERE'   => [
                'state' => [CronTaskLog::STATE_STOP, CronTaskLog::STATE_ERROR],
                'date'  => ['>', $since],
            ],
            'GROUPBY' => ['crontasks_id', 'state'],
        ]);
        foreach ($iterator as $row) {
            $id = (int) $row['crontasks_id'];
            $stats[$id] = $stats[$id] ?? ['ok' => 0, 'error' => 0, 'last_error' => null];
            if ((int) $row['state'] === CronTaskLog::STATE_ERROR) {
                $stats[$id]['error']      = (int) $row['nb'];
                $stats[$id]['last_error'] = $row['last_date'];
            } else {
                $stats[$id]['ok'] = (int) $row['nb'];
            }
        }

        $items = [];
        $recurrent = 0;
        foreach ($stats as $id => $stat) {
            if ($stat['error'] === 0) {
                continue;
            }
            $total = $stat['ok'] + $stat['error'];
            if ($stat['error'] >= 3) {
                $recurrent++;
            }
            $items[] = [
                'task'       => isset($tasks[$id]) ? $this->taskLabel($tasks[$id]) : '#' . $id,
                'errors'     => $stat['error'],
                'executions' => $total,
                'rate'       => Format::number(Format::percent($stat['error'], $total), 1) . '%',
                'last_error' => $stat['last_error'],
            ];
        }
        usort($items, static fn ($a, $b) => $b['errors'] <=> $a['errors']);

        $facts = [__('Período analisado', 'healthcheck') => sprintf(__('%d dias', 'healthcheck'), self::PERIOD_DAYS)];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma ação automática terminou com erro no período.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(
                __('%1$d tarefa(s) terminaram com erro nos últimos %2$d dias (%3$d com 3 ou mais falhas).', 'healthcheck'),
                count($items),
                self::PERIOD_DAYS,
                $recurrent
            ),
            $recurrent > 0 ? Severity::HIGH : Severity::LOW
        )
            ->impact(__('As tarefas com falha não concluem seu trabalho: notificações, coletas, limpezas ou cálculos de SLA podem estar incompletos.', 'healthcheck'))
            ->cause(__('Erros de PHP, falta de memória, tempo de execução excedido, falha de conexão com serviços externos (LDAP, SMTP, IMAP) ou bug de plugin.', 'healthcheck'))
            ->recommendation(__('Analise o histórico de cada tarefa e os logs do GLPI no horário das falhas.', 'healthcheck'))
            ->steps([
                __('Em Configurar > Ações automáticas, abra a tarefa e consulte a aba "Histórico".', 'healthcheck'),
                __('Consulte files/_log/php-errors.log, cron.log e sql-errors.log.', 'healthcheck'),
                __('Execute a tarefa manualmente em linha de comando: "php front/cron.php --force NOME_DA_TAREFA".', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'task'       => __('Tarefa', 'healthcheck'),
                'errors'     => __('Falhas', 'healthcheck'),
                'executions' => __('Execuções', 'healthcheck'),
                'rate'       => __('Taxa de falha', 'healthcheck'),
                'last_error' => __('Última falha', 'healthcheck'),
            ]);
    }
}
