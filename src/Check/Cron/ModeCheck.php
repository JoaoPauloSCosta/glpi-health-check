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

class ModeCheck extends AbstractCronCheck
{
    /** Tasks for which the internal mode is particularly harmful. */
    private const SENSITIVE_TASKS = ['queuednotification', 'mailgate', 'slaticket', 'olaticket'];

    public function getKey(): string
    {
        return 'cron.mode';
    }

    public function getTitle(): string
    {
        return __('Modo de execução das ações automáticas', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $internal = array_filter($this->getActiveTasks(CronTask::MODE_INTERNAL), [$this, 'isTaskRunnable']);
        $external = array_filter($this->getActiveTasks(CronTask::MODE_EXTERNAL), [$this, 'isTaskRunnable']);

        $facts = [
            __('Ativas em modo CLI', 'healthcheck')  => count($external),
            __('Ativas em modo GLPI', 'healthcheck') => count($internal),
        ];

        if (count($internal) === 0) {
            return CheckResult::ok(__('Todas as ações automáticas ativas usam o cron do sistema (modo CLI).', 'healthcheck'))
                ->facts($facts);
        }

        $items = [];
        $sensitive = false;
        foreach ($internal as $task) {
            $is_sensitive = in_array($task['name'], self::SENSITIVE_TASKS, true);
            $sensitive = $sensitive || $is_sensitive;
            $items[] = [
                'task'      => $this->taskLabel($task),
                'frequency' => Format::duration((int) $task['frequency']),
                'allowed'   => ((int) $task['allowmode'] & CronTask::MODE_EXTERNAL) ? __('Sim', 'healthcheck') : __('Não', 'healthcheck'),
                'sensitive' => $is_sensitive ? __('Sim', 'healthcheck') : __('Não', 'healthcheck'),
            ];
        }

        return CheckResult::warning(
            sprintf(__('%d ação(ões) automática(s) ativa(s) em modo GLPI (dependem de usuários navegando).', 'healthcheck'), count($internal)),
            $sensitive ? Severity::MEDIUM : Severity::LOW
        )
            ->impact(__('No modo GLPI as tarefas só rodam durante acessos à interface, de forma irregular e consumindo tempo das requisições dos usuários. Notificações e coletas de e-mail podem atrasar.', 'healthcheck'))
            ->cause(__('Modo padrão definido na instalação, nunca alterado para CLI.', 'healthcheck'))
            ->recommendation(__('Configure o cron do sistema e altere as tarefas para o modo CLI.', 'healthcheck'))
            ->steps([
                sprintf(__('Agende "php %s/front/cron.php" para executar a cada minuto no sistema operacional.', 'healthcheck'), GLPI_ROOT),
                __('Em Configurar > Ações automáticas, abra cada tarefa listada e altere "Modo de execução" para CLI.', 'healthcheck'),
                __('Confirme após alguns minutos que a coluna "Última execução" está sendo atualizada.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->facts($facts)
            ->items($items, [
                'task'      => __('Tarefa', 'healthcheck'),
                'frequency' => __('Frequência', 'healthcheck'),
                'allowed'   => __('CLI permitido', 'healthcheck'),
                'sensitive' => __('Crítica para operação', 'healthcheck'),
            ]);
    }
}
