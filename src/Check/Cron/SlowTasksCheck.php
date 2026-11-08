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
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Format;

/**
 * Slow operations, measured from the automatic actions history.
 */
class SlowTasksCheck extends AbstractCronCheck
{
    private const PERIOD_DAYS   = 7;
    private const SLOW_SECONDS  = 300;

    public function getKey(): string
    {
        return 'performance.slow_tasks';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Ações automáticas lentas', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db    = $this->db();
        $tasks = $this->getTasks();

        $iterator = $db->request([
            'SELECT'  => [
                'crontasks_id',
                $this->expr('COUNT(*) AS nb'),
                $this->expr('AVG(' . $db->quoteName('elapsed') . ') AS avg_elapsed'),
                $this->expr('MAX(' . $db->quoteName('elapsed') . ') AS max_elapsed'),
            ],
            'FROM'    => CronTaskLog::getTable(),
            'WHERE'   => [
                'state' => [CronTaskLog::STATE_STOP, CronTaskLog::STATE_ERROR],
                'date'  => ['>', date('Y-m-d H:i:s', $this->now() - self::PERIOD_DAYS * DAY_TIMESTAMP)],
            ],
            'GROUPBY' => 'crontasks_id',
        ]);

        $items = [];
        $measured = 0;
        foreach ($iterator as $row) {
            $measured++;
            if ((float) $row['max_elapsed'] < self::SLOW_SECONDS) {
                continue;
            }
            $id = (int) $row['crontasks_id'];
            $items[] = [
                'task'    => isset($tasks[$id]) ? $this->taskLabel($tasks[$id]) : '#' . $id,
                'runs'    => (int) $row['nb'],
                'average' => Format::duration((int) round((float) $row['avg_elapsed'])),
                'max'     => Format::duration((int) round((float) $row['max_elapsed'])),
            ];
        }

        $facts = [
            __('Tarefas com histórico no período', 'healthcheck') => $measured,
            __('Limite considerado lento', 'healthcheck')         => Format::duration(self::SLOW_SECONDS),
        ];

        if ($measured === 0) {
            return CheckResult::skipped(__('Não há histórico de execução de ações automáticas nos últimos 7 dias para medir a duração.', 'healthcheck'))
                ->facts($facts);
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma ação automática demorou mais de 5 minutos nos últimos 7 dias.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(__('%d ação(ões) automática(s) com execuções acima de 5 minutos.', 'healthcheck'), count($items)),
            Severity::LOW
        )
            ->impact(__('Tarefas longas atrasam as demais tarefas do cron e podem ser interrompidas por limite de tempo, ficando travadas.', 'healthcheck'))
            ->cause(__('Grande volume de dados a processar, consultas lentas, servidores externos lentos (LDAP, e-mail) ou falta de índices.', 'healthcheck'))
            ->recommendation(__('Reduza o volume processado por execução (parâmetro da tarefa), aumente a frequência e revise índices e desempenho do banco.', 'healthcheck'))
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'task'    => __('Tarefa', 'healthcheck'),
                'runs'    => __('Execuções', 'healthcheck'),
                'average' => __('Duração média', 'healthcheck'),
                'max'     => __('Duração máxima', 'healthcheck'),
            ]);
    }
}
