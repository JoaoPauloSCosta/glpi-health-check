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
use MailCollector;

/**
 * Essential, maintenance and cleanup tasks that should not be disabled.
 */
class EssentialTasksCheck extends AbstractCronCheck
{
    public function getKey(): string
    {
        return 'cron.essential';
    }

    public function getTitle(): string
    {
        return __('Tarefas essenciais e rotinas de limpeza', 'healthcheck');
    }

    /**
     * @return array<string, array{label: string, severity: int, condition: bool}>
     */
    private function getEssentialTasks(): array
    {
        $notifications   = (bool) $this->cfg('use_notifications', 0);
        $has_collectors  = $this->countRows(MailCollector::getTable(), ['is_active' => 1]) > 0;

        return [
            'queuednotification'      => [
                'label'     => __('Envio da fila de notificações', 'healthcheck'),
                'severity'  => Severity::CRITICAL,
                'condition' => $notifications,
            ],
            'mailgate'                => [
                'label'     => __('Coleta de e-mails (criação de chamados)', 'healthcheck'),
                'severity'  => Severity::CRITICAL,
                'condition' => $has_collectors,
            ],
            'slaticket'               => [
                'label'     => __('Escalonamento de SLA', 'healthcheck'),
                'severity'  => Severity::MEDIUM,
                'condition' => true,
            ],
            'olaticket'               => [
                'label'     => __('Escalonamento de OLA', 'healthcheck'),
                'severity'  => Severity::LOW,
                'condition' => true,
            ],
            'session'                 => [
                'label'     => __('Limpeza de sessões expiradas', 'healthcheck'),
                'severity'  => Severity::MEDIUM,
                'condition' => true,
            ],
            'temp'                    => [
                'label'     => __('Limpeza de arquivos temporários', 'healthcheck'),
                'severity'  => Severity::MEDIUM,
                'condition' => true,
            ],
            'logs'                    => [
                'label'     => __('Limpeza do histórico das ações automáticas (desativada por padrão na instalação)', 'healthcheck'),
                'severity'  => Severity::LOW,
                'condition' => true,
            ],
            'circularlogs'            => [
                'label'     => __('Rotação dos arquivos de log (desativada por padrão na instalação)', 'healthcheck'),
                'severity'  => Severity::LOW,
                'condition' => true,
            ],
            'queuednotificationclean' => [
                'label'     => __('Limpeza da fila de notificações', 'healthcheck'),
                'severity'  => Severity::LOW,
                'condition' => $notifications,
            ],
            'PurgeLogs'               => [
                'label'     => __('Purga do histórico de alterações (conforme configuração)', 'healthcheck'),
                'severity'  => Severity::LOW,
                'condition' => true,
            ],
            'unlockobject'            => [
                'label'     => __('Desbloqueio de objetos bloqueados', 'healthcheck'),
                'severity'  => Severity::LOW,
                // Only relevant when record locking is enabled
                'condition' => (bool) $this->cfg('lock_use_lock_item', 0),
            ],
        ];
    }

    public function run(): CheckResult
    {
        $by_name = [];
        foreach ($this->getTasks() as $task) {
            if (isPluginItemType($task['itemtype']) === false) {
                $by_name[$task['name']] = $task;
            }
        }

        $items    = [];
        $findings = [];
        foreach ($this->getEssentialTasks() as $name => $spec) {
            if (!$spec['condition']) {
                continue;
            }
            $task = $by_name[$name] ?? null;
            if ($task === null) {
                $problem = __('Não registrada', 'healthcheck');
            } elseif ((int) $task['state'] === CronTask::STATE_DISABLE) {
                $problem = __('Desativada', 'healthcheck');
            } else {
                continue;
            }
            $findings[] = $spec['severity'] >= Severity::CRITICAL
                ? [Status::CRITICAL, Severity::HIGH]
                : [Status::WARNING, $spec['severity']];
            $items[] = [
                'task'     => $name,
                'function' => $spec['label'],
                'problem'  => $problem,
                'severity' => Severity::getLabel($spec['severity']),
            ];
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('As tarefas essenciais e de limpeza estão ativas.', 'healthcheck'));
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d tarefa(s) essencial(is) ou de manutenção desativada(s).', 'healthcheck'), count($items))
        )
            ->impact(__('Notificações e e-mails podem deixar de ser processados e arquivos temporários, sessões e logs crescem sem limite, consumindo disco.', 'healthcheck'))
            ->cause(__('Tarefas desativadas manualmente (por exemplo durante uma migração) e não reativadas.', 'healthcheck'))
            ->recommendation(__('Reative as tarefas listadas.', 'healthcheck'))
            ->steps([
                __('Acesse Configurar > Ações automáticas.', 'healthcheck'),
                __('Abra cada tarefa listada e altere o status para "Agendada".', 'healthcheck'),
                __('Prefira o modo de execução CLI com o cron do sistema configurado.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->items($items, [
                'task'     => __('Tarefa', 'healthcheck'),
                'function' => __('Função', 'healthcheck'),
                'problem'  => __('Situação', 'healthcheck'),
                'severity' => __('Criticidade', 'healthcheck'),
            ]);
    }
}
