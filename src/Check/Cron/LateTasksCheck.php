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

/**
 * Tasks that should have run while the cron itself is running (task specific delay).
 * A global cron stop is reported by ExecutionCheck, not here.
 */
class LateTasksCheck extends AbstractCronCheck
{
    public function getKey(): string
    {
        return 'cron.late';
    }

    public function getTitle(): string
    {
        return __('Tarefas atrasadas ou pendentes', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $tasks = array_filter($this->getActiveTasks(), [$this, 'isTaskRunnable']);

        // Newest/oldest run per mode, to distinguish "task late" from "cron stopped"
        // and to know for how long the cron of each mode has been active.
        $newest = [CronTask::MODE_INTERNAL => null, CronTask::MODE_EXTERNAL => null];
        $oldest = [CronTask::MODE_INTERNAL => null, CronTask::MODE_EXTERNAL => null];
        foreach ($tasks as $task) {
            if (!empty($task['lastrun'])) {
                $ts = strtotime($task['lastrun']);
                $mode = (int) $task['mode'];
                if ($ts === false) {
                    continue;
                }
                if ($newest[$mode] === null || $ts > $newest[$mode]) {
                    $newest[$mode] = $ts;
                }
                if ($oldest[$mode] === null || $ts < $oldest[$mode]) {
                    $oldest[$mode] = $ts;
                }
            }
        }

        $items = [];
        $never = 0;
        foreach ($tasks as $task) {
            if ((int) $task['state'] === CronTask::STATE_RUNNING) {
                continue; // handled by the stuck tasks check
            }
            $mode       = (int) $task['mode'];
            $frequency  = max(60, (int) $task['frequency']);
            $restricted = (int) $task['hourmin'] !== 0 || (int) $task['hourmax'] !== 24;
            $grace      = max($frequency, HOUR_TIMESTAMP) + ($restricted ? DAY_TIMESTAMP : 0);

            if (empty($task['lastrun'])) {
                // Never executed although the cron of the same mode has been active for longer
                // than the task period (avoids false positives right after installation).
                if (!empty($task['date_creation'])) {
                    $known_for = $this->now() - (int) strtotime($task['date_creation']);
                } else {
                    $known_for = $newest[$mode] !== null ? $newest[$mode] - (int) $oldest[$mode] : 0;
                }
                if ($newest[$mode] !== null && $frequency <= WEEK_TIMESTAMP && $known_for > $frequency + $grace) {
                    $never++;
                    $items[] = [
                        'task'     => $this->taskLabel($task),
                        'mode'     => self::modeLabel($mode),
                        'lastrun'  => __('Nunca', 'healthcheck'),
                        'expected' => Format::duration($frequency),
                        'delay'    => '-',
                    ];
                }
                continue;
            }

            $expected = strtotime($task['lastrun']) + $frequency;
            $delay    = $this->now() - $expected;
            if ($delay > $grace && $newest[$mode] !== null && $newest[$mode] > $expected + $grace) {
                $items[] = [
                    'task'     => $this->taskLabel($task),
                    'mode'     => self::modeLabel($mode),
                    'lastrun'  => $task['lastrun'],
                    'expected' => Format::duration($frequency),
                    'delay'    => Format::duration($delay),
                ];
            }
        }

        $facts = [
            __('Tarefas ativas analisadas', 'healthcheck') => count($tasks),
            __('Limite de tarefas por execução (cron_limit)', 'healthcheck') => (int) $this->cfg('cron_limit', 0),
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma tarefa ativa está atrasada.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(__('%d tarefa(s) atrasada(s) ou nunca executada(s), embora o cron esteja funcionando.', 'healthcheck'), count($items)),
            count($items) - $never > 3 ? Severity::HIGH : Severity::MEDIUM
        )
            ->impact(__('As funções dessas tarefas (alertas, limpezas, SLAs, notificações) não estão sendo processadas no prazo.', 'healthcheck'))
            ->cause(__('Limite de tarefas por execução muito baixo, faixa de horário restritiva, erro recorrente na tarefa ou execução bloqueada.', 'healthcheck'))
            ->recommendation(__('Revise a configuração de cada tarefa e o número máximo de tarefas por execução.', 'healthcheck'))
            ->steps([
                __('Em Configurar > Ações automáticas, abra a tarefa e consulte a aba "Histórico".', 'healthcheck'),
                __('Execute a tarefa manualmente: "php front/cron.php --force NOME_DA_TAREFA" (ou o botão "Executar").', 'healthcheck'),
                __('Se muitas tarefas estiverem atrasadas, aumente "Número máximo de ações executadas por execução" em Configurar > Geral > Sistema.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'task'     => __('Tarefa', 'healthcheck'),
                'mode'     => __('Modo', 'healthcheck'),
                'lastrun'  => __('Última execução', 'healthcheck'),
                'expected' => __('Frequência', 'healthcheck'),
                'delay'    => __('Atraso', 'healthcheck'),
            ]);
    }
}
