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

class ExecutionCheck extends AbstractCronCheck
{
    public function getKey(): string
    {
        return 'cron.last_execution';
    }

    public function getTitle(): string
    {
        return __('Funcionamento do cron', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Verifica se as ações automáticas estão sendo executadas, a partir da data da última execução.', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $external = array_filter($this->getActiveTasks(CronTask::MODE_EXTERNAL), [$this, 'isTaskRunnable']);
        $internal = array_filter($this->getActiveTasks(CronTask::MODE_INTERNAL), [$this, 'isTaskRunnable']);

        $last_external = $this->newestRun($external);
        $last_internal = $this->newestRun($internal);

        $facts = [
            __('Tarefas ativas em modo CLI', 'healthcheck')      => count($external),
            __('Tarefas ativas em modo GLPI', 'healthcheck')     => count($internal),
            __('Última execução (modo CLI)', 'healthcheck')      => $last_external !== null ? date('Y-m-d H:i:s', $last_external) : __('Nunca', 'healthcheck'),
            __('Última execução (modo GLPI)', 'healthcheck')     => $last_internal !== null ? date('Y-m-d H:i:s', $last_internal) : __('Nunca', 'healthcheck'),
        ];

        $steps_cli = [
            __('No servidor, edite o crontab do usuário do servidor web (ex.: "crontab -u www-data -e").', 'healthcheck'),
            sprintf(__('Adicione a linha: "* * * * * php %s/front/cron.php"', 'healthcheck'), GLPI_ROOT),
            __('No Windows, crie uma tarefa agendada que execute "php.exe front\\cron.php" a cada minuto.', 'healthcheck'),
            __('Verifique se o PHP de linha de comando é o mesmo usado pelo servidor web.', 'healthcheck'),
        ];

        if (count($external) > 0) {
            $min_frequency = min(array_map(static fn ($t) => max(60, (int) $t['frequency']), $external));
            $threshold     = max(15 * MINUTE_TIMESTAMP, 3 * $min_frequency);
            $facts[__('Tolerância considerada', 'healthcheck')] = Format::duration($threshold);

            if ($last_external === null) {
                return CheckResult::critical(__('O cron do sistema nunca executou as tarefas configuradas em modo CLI.', 'healthcheck'), Severity::HIGH)
                    ->impact(__('Notificações não são enviadas, e-mails não são coletados, SLAs não são processados e as limpezas de manutenção não ocorrem.', 'healthcheck'))
                    ->cause(__('O agendamento de "front/cron.php" não foi configurado no sistema operacional.', 'healthcheck'))
                    ->recommendation(__('Configure o cron do sistema para executar o GLPI a cada minuto.', 'healthcheck'))
                    ->steps($steps_cli)
                    ->doc(self::DOC_INSTALL)
                    ->facts($facts);
            }

            $age = $this->now() - $last_external;
            if ($age > $threshold) {
                return CheckResult::critical(
                    sprintf(__('O cron do sistema não executa há %s.', 'healthcheck'), Format::duration($age)),
                    Severity::HIGH
                )
                    ->impact(__('Notificações não são enviadas, e-mails não são coletados, SLAs não são processados e as limpezas de manutenção não ocorrem.', 'healthcheck'))
                    ->cause(__('Serviço cron parado, entrada do crontab removida, erro fatal do PHP na linha de comando ou bloqueio de execução.', 'healthcheck'))
                    ->recommendation(__('Verifique o agendamento e execute manualmente "php front/cron.php" para ver possíveis erros.', 'healthcheck'))
                    ->steps(array_merge(
                        [__('Execute "php front/cron.php" manualmente e observe a saída.', 'healthcheck')],
                        $steps_cli,
                        [__('Consulte files/_log/cron.log e php-errors.log.', 'healthcheck')]
                    ))
                    ->doc(self::DOC_INSTALL)
                    ->facts($facts);
            }
        }

        if (count($internal) > 0 && ($last_internal === null || ($this->now() - $last_internal) > DAY_TIMESTAMP)) {
            return CheckResult::warning(
                $last_internal === null
                    ? __('As tarefas em modo GLPI nunca foram executadas.', 'healthcheck')
                    : __('Tarefas em modo GLPI não executam há mais de 24 horas.', 'healthcheck'),
                Severity::MEDIUM
            )
                ->impact(__('No modo GLPI, as tarefas só executam quando há usuários navegando. Em períodos sem acesso nada é processado.', 'healthcheck'))
                ->cause(__('Ausência de acessos à interface ou tarefas sem permissão de execução.', 'healthcheck'))
                ->recommendation(__('Configure o cron do sistema e altere as tarefas para o modo CLI.', 'healthcheck'))
                ->steps(array_merge($steps_cli, [
                    __('Em Configurar > Ações automáticas, altere o "Modo de execução" das tarefas para CLI.', 'healthcheck'),
                ]))
                ->doc(self::DOC_INSTALL)
                ->facts($facts);
        }

        if (count($external) === 0 && count($internal) === 0) {
            return CheckResult::warning(__('Nenhuma ação automática está ativa.', 'healthcheck'), Severity::HIGH)
                ->impact(__('Nenhuma rotina de manutenção, notificação ou coleta é executada.', 'healthcheck'))
                ->recommendation(__('Reative as ações automáticas essenciais em Configurar > Ações automáticas.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::ok(__('As ações automáticas estão sendo executadas regularmente.', 'healthcheck'))
            ->facts($facts);
    }

    private function newestRun(array $tasks): ?int
    {
        $newest = null;
        foreach ($tasks as $task) {
            if (empty($task['lastrun'])) {
                continue;
            }
            $ts = strtotime($task['lastrun']);
            if ($ts !== false && ($newest === null || $ts > $newest)) {
                $newest = $ts;
            }
        }
        return $newest;
    }
}
