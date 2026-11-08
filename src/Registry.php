<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use GlpiPlugin\Healthcheck\Check;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use Plugin;

/**
 * List of available checks.
 *
 * Other plugins can contribute checks by declaring, in their plugin_init_xxx() function:
 *
 *     $PLUGIN_HOOKS['healthcheck_checks']['myplugin'] = [\GlpiPlugin\Myplugin\MyCheck::class];
 *
 * where each class extends GlpiPlugin\Healthcheck\Check\AbstractCheck.
 */
final class Registry
{
    public const HOOK = 'healthcheck_checks';

    /**
     * @return class-string<AbstractCheck>[]
     */
    private static function getCoreClasses(): array
    {
        return [
            // 1. Update and requirements
            Check\Update\GlpiVersionCheck::class,
            Check\Update\PhpVersionCheck::class,
            Check\Update\RequirementsCheck::class,
            Check\Update\DbSchemaVersionCheck::class,
            Check\Update\PendingMigrationsCheck::class,
            // 2. Database
            Check\Database\ConnectionCheck::class,
            Check\Database\EngineVersionCheck::class,
            Check\Database\ConfigurationCheck::class,
            Check\Database\TablesStatusCheck::class,
            Check\Database\SchemaIntegrityCheck::class,
            Check\Database\IndexesCheck::class,
            // 3. Cron
            Check\Cron\ExecutionCheck::class,
            Check\Cron\ModeCheck::class,
            Check\Cron\EssentialTasksCheck::class,
            Check\Cron\LateTasksCheck::class,
            Check\Cron\StuckTasksCheck::class,
            Check\Cron\FailuresCheck::class,
            // 4. Email
            Check\Email\ConfigurationCheck::class,
            Check\Email\QueueCheck::class,
            Check\Email\ErrorLogCheck::class,
            Check\Email\CollectorsCheck::class,
            Check\Email\RejectedMailsCheck::class,
            Check\Email\ConnectivityCheck::class,
            // 5. Directories and permissions
            Check\Filesystem\WritableDirectoriesCheck::class,
            Check\Filesystem\OpenPermissionsCheck::class,
            // 6. Plugins
            Check\Plugins\StateCheck::class,
            Check\Plugins\CompatibilityCheck::class,
            Check\Plugins\UpdatesCheck::class,
            // 7. Security
            Check\Security\DefaultPasswordsCheck::class,
            Check\Security\HttpsCheck::class,
            Check\Security\DocumentRootCheck::class,
            Check\Security\SensitiveFilesCheck::class,
            Check\Security\SessionSecurityCheck::class,
            Check\Security\PhpSecurityCheck::class,
            Check\Security\PasswordPolicyCheck::class,
            Check\Security\AdminAccessCheck::class,
            Check\Security\ApiSecurityCheck::class,
            // 8. Performance
            Check\Performance\DiskSpaceCheck::class,
            Check\Performance\MemoryCheck::class,
            Check\Performance\PhpSettingsCheck::class,
            Check\Performance\CacheCheck::class,
            Check\Performance\LogVolumeCheck::class,
            Check\Performance\DatabaseVolumeCheck::class,
            Check\Performance\DatabaseServerCheck::class,
            Check\Cron\SlowTasksCheck::class,
            // 9. Operational integrity
            Check\Integrity\OrphanRecordsCheck::class,
            Check\Integrity\UsersConsistencyCheck::class,
            Check\Integrity\EntitiesCheck::class,
            Check\Integrity\AssetsOwnerCheck::class,
            Check\Integrity\RulesCheck::class,
            Check\Integrity\DocumentFilesCheck::class,
        ];
    }

    /**
     * Instantiate every check (core + contributed by active plugins).
     *
     * @param array<int, array{class: string, error: string}> $errors invalid contributed classes
     * @return AbstractCheck[] indexed by check key
     */
    public static function getChecks(array &$errors = []): array
    {
        /** @var array $PLUGIN_HOOKS */
        global $PLUGIN_HOOKS;

        $classes = self::getCoreClasses();
        foreach ($PLUGIN_HOOKS[self::HOOK] ?? [] as $plugin_key => $plugin_classes) {
            if (!Plugin::isPluginActive($plugin_key)) {
                continue;
            }
            foreach ((array) $plugin_classes as $class) {
                $classes[] = $class;
            }
        }

        $checks = [];
        foreach ($classes as $class) {
            if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, AbstractCheck::class)) {
                $errors[] = ['class' => (string) $class, 'error' => 'Invalid check class'];
                continue;
            }
            try {
                /** @var AbstractCheck $check */
                $check = new $class();
                $checks[$check->getKey()] = $check;
            } catch (\Throwable $e) {
                $errors[] = ['class' => $class, 'error' => $e->getMessage()];
            }
        }
        return $checks;
    }
}
