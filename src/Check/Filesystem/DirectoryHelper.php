<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Filesystem;

final class DirectoryHelper
{
    public const WRITE_REQUIRED = 'required';
    public const WRITE_OPTIONAL = 'optional';
    public const WRITE_NONE     = 'none';

    /**
     * Directories used by GLPI.
     *
     * @return array<string, array{label: string, path: string, write: string}>
     */
    public static function getDirectories(): array
    {
        $dirs = [
            'GLPI_DOC_DIR'        => [__('Arquivos e documentos', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_UPLOAD_DIR'     => [__('Uploads', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_PICTURE_DIR'    => [__('Imagens', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_CACHE_DIR'      => [__('Cache', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_SESSION_DIR'    => [__('Sessões', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_TMP_DIR'        => [__('Temporários', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_LOG_DIR'        => [__('Logs', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_CRON_DIR'       => [__('Cron', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_LOCK_DIR'       => [__('Bloqueios (lock)', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_GRAPH_DIR'      => [__('Gráficos', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_RSS_DIR'        => [__('RSS', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_DUMP_DIR'       => [__('Dumps', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_PLUGIN_DOC_DIR' => [__('Arquivos de plugins', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_INVENTORY_DIR'  => [__('Inventário', 'healthcheck'), self::WRITE_REQUIRED],
            'GLPI_MARKETPLACE_DIR' => [__('Marketplace', 'healthcheck'), self::WRITE_OPTIONAL],
            'GLPI_CONFIG_DIR'     => [__('Configuração', 'healthcheck'), self::WRITE_NONE],
        ];

        $result = [];
        foreach ($dirs as $constant => [$label, $write]) {
            if (!defined($constant)) {
                continue;
            }
            if ($constant === 'GLPI_SESSION_DIR' && ini_get('session.save_handler') !== 'files') {
                continue; // sessions not stored on disk
            }
            $result[$constant] = [
                'label' => $label,
                'path'  => (string) constant($constant),
                'write' => $write,
            ];
        }
        $result['PLUGINS'] = [
            'label' => __('Plugins', 'healthcheck'),
            'path'  => GLPI_ROOT . '/plugins',
            'write' => self::WRITE_NONE,
        ];
        return $result;
    }

    public static function isPosix(): bool
    {
        return DIRECTORY_SEPARATOR === '/';
    }

    public static function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name'])) {
                return $info['name'];
            }
        }
        $user = getenv('USERNAME') ?: getenv('USER');
        return $user !== false && $user !== '' ? $user : get_current_user();
    }

    public static function owner(string $path): string
    {
        $uid = @fileowner($path);
        if ($uid === false) {
            return '-';
        }
        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid($uid);
            if (is_array($info) && isset($info['name'])) {
                return $info['name'];
            }
        }
        return self::isPosix() ? (string) $uid : '-';
    }

    public static function mode(string $path): string
    {
        if (!self::isPosix()) {
            return '-';
        }
        $perms = @fileperms($path);
        return $perms === false ? '-' : substr(sprintf('%o', $perms), -4);
    }

    /**
     * Normalized absolute path (for "is inside" comparisons).
     */
    public static function normalize(string $path): string
    {
        $real = realpath($path);
        $path = $real !== false ? $real : $path;
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return self::isPosix() ? $path : strtolower($path);
    }

    public static function isInside(string $path, string $parent): bool
    {
        $path   = self::normalize($path) . '/';
        $parent = self::normalize($parent) . '/';
        return strpos($path, $parent) === 0;
    }
}
