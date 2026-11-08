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
use GlpiPlugin\Healthcheck\Format;

class StuckTasksCheck extends AbstractCronCheck
{
    private const THRESHOLD = HOUR_TIMESTAMP;

    public function getKey(): string
    {
        return 'cron.stuck';
    }

    public function getTitle(): string
    {
        return __('Tarefas travadas em execução', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $items = [];
        foreach ($this->getTasks() as $task) {
            if ((int) $task['state'] !== CronTask::STATE_RUNNING || empty($task['lastrun'])) {
                continue;
            }
            $age = $this->now() - strtotime($task['lastrun']);
            if ($age > self::THRESHOLD) {
                $items[] = [
                    'task'    => $this->taskLabel($task),
                    'since'   => $task['lastrun'],
                    'running' => Format::duration($age),
                ];
            }
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma tarefa está travada em execução.', 'healthcheck'));
        }

        return CheckResult::warning(
            sprintf(__('%d tarefa(s) presa(s) no estado "Em execução" há mais de 1 hora.', 'healthcheck'), count($items)),
            Severity::HIGH
        )
            ->impact(__('Uma tarefa marcada como em execução não é iniciada novamente até ser desbloqueada; sua função fica parada.', 'healthcheck'))
            ->cause(__('O processo PHP foi interrompido (timeout, falta de memória, reinício do servidor) antes de concluir a tarefa.', 'healthcheck'))
            ->recommendation(__('Verifique os logs do período e desbloqueie a tarefa.', 'healthcheck'))
            ->steps([
                __('Consulte files/_log/php-errors.log e cron.log próximo do horário indicado.', 'healthcheck'),
                __('Em Configurar > Ações automáticas, abra a tarefa e altere o status para "Agendada" (ou use "php bin/console task:unlock --all").', 'healthcheck'),
                __('Se o problema se repetir, aumente memory_limit/max_execution_time do PHP CLI ou reduza o volume processado por execução.', 'healthcheck'),
            ])
            ->doc(self::DOC_CLI)
            ->items($items, [
                'task'    => __('Tarefa', 'healthcheck'),
                'since'   => __('Iniciada em', 'healthcheck'),
                'running' => __('Em execução há', 'healthcheck'),
            ]);
    }
}
