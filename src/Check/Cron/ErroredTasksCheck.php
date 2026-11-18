<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Cron;

use CronTask;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * Automatic actions blocked in the ERROR state (GLPI 12+).
 *
 * Since GLPI 12 an action that fails 5 times in a row is put in the ERROR state and is not run
 * anymore until someone changes its status manually. Before reaching it, failed actions are
 * retried with an exponential backoff, tracked by the "error_count" field.
 *
 * Only registered by the Registry when CronTask::STATE_ERROR exists.
 */
class ErroredTasksCheck extends AbstractCronCheck
{
    public function getKey(): string
    {
        return 'cron.errored';
    }

    public function getTitle(): string
    {
        return __('Ações automáticas bloqueadas em erro', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Desde o GLPI 12, uma ação automática que falha 5 vezes seguidas fica no status "Erro" e só volta a executar após intervenção manual.', 'healthcheck');
    }

    public static function isSupported(): bool
    {
        return defined(CronTask::class . '::STATE_ERROR');
    }

    public function run(): CheckResult
    {
        if (!self::isSupported()) {
            return CheckResult::skipped(__('Esta versão do GLPI não possui o status "Erro" para ações automáticas.', 'healthcheck'));
        }

        $has_counter = $this->fieldExists(CronTask::getTable(), 'error_count');
        $state_error = (int) constant(CronTask::class . '::STATE_ERROR');

        $items    = [];
        $findings = [];
        $blocked  = 0;
        $retrying = 0;
        foreach ($this->getTasks() as $task) {
            if (!$this->isTaskRunnable($task)) {
                continue;
            }
            $errors = $has_counter ? (int) $task['error_count'] : 0;

            if ((int) $task['state'] === $state_error) {
                $blocked++;
                $findings[] = [Status::WARNING, Severity::HIGH];
                $situation  = __('Bloqueada (status "Erro")', 'healthcheck');
            } elseif ($errors > 0 && (int) $task['state'] !== CronTask::STATE_DISABLE) {
                $retrying++;
                $findings[] = [Status::WARNING, Severity::LOW];
                $situation  = __('Falhando, em nova tentativa', 'healthcheck');
            } else {
                continue;
            }

            $items[] = [
                'task'      => $this->taskLabel($task),
                'situation' => $situation,
                'errors'    => $has_counter ? $errors : '-',
                'lastrun'   => $task['lastrun'] ?? '-',
            ];
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma ação automática está em erro.', 'healthcheck'));
        }

        $messages = [];
        if ($blocked > 0) {
            $messages[] = sprintf(__('%d ação(ões) automática(s) bloqueada(s) no status "Erro" após falhas consecutivas.', 'healthcheck'), $blocked);
        }
        if ($retrying > 0) {
            $messages[] = sprintf(__('%d ação(ões) automática(s) falhando e sendo reexecutada(s) com espera progressiva.', 'healthcheck'), $retrying);
        }

        return CheckResult::worstOf($findings, '', implode(' ', $messages))
            ->impact(__('Uma ação no status "Erro" não é mais executada: a função que ela realiza (envio de e-mails, coleta, limpezas...) fica parada até a intervenção manual.', 'healthcheck'))
            ->cause(__('A ação terminou com erro fatal, falta de memória ou tempo limite excedido em 5 execuções seguidas, geralmente por problema de configuração, de serviço externo ou de plugin.', 'healthcheck'))
            ->recommendation(__('Corrija a causa da falha antes de reativar a ação; caso contrário ela voltará ao status "Erro".', 'healthcheck'))
            ->steps([
                __('Consulte files/_log/php-errors.log e cron.log próximo do horário da última execução.', 'healthcheck'),
                __('Em Configurar > Ações automáticas, abra a tarefa e consulte a aba "Histórico".', 'healthcheck'),
                __('Após corrigir a causa, altere o status da tarefa para "Agendada".', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->items($items, [
                'task'      => __('Tarefa', 'healthcheck'),
                'situation' => __('Situação', 'healthcheck'),
                'errors'    => __('Falhas consecutivas', 'healthcheck'),
                'lastrun'   => __('Última execução', 'healthcheck'),
            ]);
    }
}
