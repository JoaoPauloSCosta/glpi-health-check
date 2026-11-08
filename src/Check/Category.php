<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check;

final class Category
{
    public const UPDATE      = 'update';
    public const DATABASE    = 'database';
    public const CRON        = 'cron';
    public const EMAIL       = 'email';
    public const FILESYSTEM  = 'filesystem';
    public const PLUGINS     = 'plugins';
    public const SECURITY    = 'security';
    public const PERFORMANCE = 'performance';
    public const INTEGRITY   = 'integrity';

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function all(): array
    {
        return [
            self::UPDATE      => ['label' => __('Atualização e requisitos', 'healthcheck'), 'icon' => 'ti ti-refresh'],
            self::DATABASE    => ['label' => __('Banco de dados', 'healthcheck'), 'icon' => 'ti ti-database'],
            self::CRON        => ['label' => __('Cron e ações automáticas', 'healthcheck'), 'icon' => 'ti ti-clock'],
            self::EMAIL       => ['label' => __('E-mail e notificações', 'healthcheck'), 'icon' => 'ti ti-mail'],
            self::FILESYSTEM  => ['label' => __('Diretórios e permissões', 'healthcheck'), 'icon' => 'ti ti-folder'],
            self::PLUGINS     => ['label' => __('Plugins', 'healthcheck'), 'icon' => 'ti ti-puzzle'],
            self::SECURITY    => ['label' => __('Segurança', 'healthcheck'), 'icon' => 'ti ti-shield-lock'],
            self::PERFORMANCE => ['label' => __('Desempenho', 'healthcheck'), 'icon' => 'ti ti-gauge'],
            self::INTEGRITY   => ['label' => __('Integridade operacional', 'healthcheck'), 'icon' => 'ti ti-link'],
        ];
    }

    public static function getLabel(string $category): string
    {
        return self::all()[$category]['label'] ?? $category;
    }

    public static function getIcon(string $category): string
    {
        return self::all()[$category]['icon'] ?? 'ti ti-list-check';
    }
}
