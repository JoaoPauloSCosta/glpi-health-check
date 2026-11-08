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
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;

abstract class AbstractCronCheck extends AbstractCheck
{
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $tasks = null;

    public function getCategory(): string
    {
        return Category::CRON;
    }

    /**
     * Every registered automatic action.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getTasks(): array
    {
        if (self::$tasks === null) {
            self::$tasks = [];
            foreach ($this->db()->request(['FROM' => CronTask::getTable(), 'ORDER' => ['itemtype', 'name']]) as $row) {
                self::$tasks[(int) $row['id']] = $row;
            }
        }
        return self::$tasks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getActiveTasks(?int $mode = null): array
    {
        return array_filter(
            $this->getTasks(),
            static fn ($task) => (int) $task['state'] !== CronTask::STATE_DISABLE
                && ($mode === null || (int) $task['mode'] === $mode)
        );
    }

    /**
     * Plugin tasks whose plugin is not active never run; ignore them.
     */
    public function isTaskRunnable(array $task): bool
    {
        $plug = isPluginItemType($task['itemtype']);
        if ($plug !== false) {
            return \Plugin::isPluginActive(strtolower($plug['plugin']));
        }
        return true;
    }

    protected function taskLabel(array $task): string
    {
        return $task['itemtype'] . ' / ' . $task['name'];
    }

    protected static function modeLabel(int $mode): string
    {
        return $mode === CronTask::MODE_EXTERNAL ? __('CLI (cron do sistema)', 'healthcheck') : __('GLPI (interno)', 'healthcheck');
    }

    /**
     * Reset per-run cache (used by the runner between executions in the same process).
     */
    public static function resetCache(): void
    {
        self::$tasks = null;
    }
}
