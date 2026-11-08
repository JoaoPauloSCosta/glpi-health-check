<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Filesystem;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * World writable directories and world readable secrets (POSIX systems only).
 */
class OpenPermissionsCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'filesystem.open_permissions';
    }

    public function getCategory(): string
    {
        return Category::FILESYSTEM;
    }

    public function getTitle(): string
    {
        return __('Permissões excessivamente abertas', 'healthcheck');
    }

    public function run(): CheckResult
    {
        if (!DirectoryHelper::isPosix()) {
            return CheckResult::skipped(__('Servidor Windows: as permissões são controladas por ACLs NTFS e não podem ser avaliadas pelos bits POSIX. Verifique manualmente que apenas o usuário do serviço web e administradores têm acesso de escrita.', 'healthcheck'));
        }

        $items    = [];
        $findings = [];

        $targets = [];
        foreach (DirectoryHelper::getDirectories() as $dir) {
            $targets[$dir['path']] = $dir['label'];
        }
        $targets[GLPI_ROOT] = __('Raiz da aplicação', 'healthcheck');
        foreach (['src', 'inc', 'vendor', 'front', 'ajax'] as $code_dir) {
            $targets[GLPI_ROOT . '/' . $code_dir] = sprintf(__('Código da aplicação (%s)', 'healthcheck'), $code_dir);
        }

        foreach ($targets as $path => $label) {
            if (!file_exists($path)) {
                continue;
            }
            $perms = @fileperms($path);
            if ($perms === false) {
                continue;
            }
            if ($perms & 0002) {
                $findings[] = [Status::WARNING, Severity::HIGH];
                $items[] = [
                    'target'  => $label,
                    'path'    => $path,
                    'mode'    => DirectoryHelper::mode($path),
                    'problem' => __('Gravável por qualquer usuário do sistema (o+w)', 'healthcheck'),
                ];
            }
        }

        // Code directories writable by the PHP user
        foreach (['src', 'inc', 'vendor'] as $code_dir) {
            $path = GLPI_ROOT . '/' . $code_dir;
            if (is_dir($path) && is_writable($path)) {
                $findings[] = [Status::WARNING, Severity::LOW];
                $items[] = [
                    'target'  => sprintf(__('Código da aplicação (%s)', 'healthcheck'), $code_dir),
                    'path'    => $path,
                    'mode'    => DirectoryHelper::mode($path),
                    'problem' => __('Gravável pelo usuário do PHP: uma invasão poderia alterar o código do GLPI', 'healthcheck'),
                ];
            }
        }

        // Secrets
        foreach (['config_db.php', 'glpicrypt.key', 'config_db_slave.php'] as $secret) {
            $path = GLPI_CONFIG_DIR . '/' . $secret;
            if (!file_exists($path)) {
                continue;
            }
            $perms = @fileperms($path);
            if ($perms !== false && ($perms & 0004)) {
                $findings[] = [Status::WARNING, Severity::HIGH];
                $items[] = [
                    'target'  => $secret,
                    'path'    => $path,
                    'mode'    => DirectoryHelper::mode($path),
                    'problem' => __('Legível por qualquer usuário do sistema (o+r): expõe credenciais do banco ou a chave de criptografia', 'healthcheck'),
                ];
            }
            if ($perms !== false && ($perms & 0002)) {
                $findings[] = [Status::WARNING, Severity::HIGH];
                $items[] = [
                    'target'  => $secret,
                    'path'    => $path,
                    'mode'    => DirectoryHelper::mode($path),
                    'problem' => __('Gravável por qualquer usuário do sistema (o+w)', 'healthcheck'),
                ];
            }
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma permissão excessivamente aberta foi encontrada.', 'healthcheck'));
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d permissão(ões) excessivamente aberta(s).', 'healthcheck'), count($items))
        )
            ->impact(__('Outros usuários ou processos do servidor podem ler credenciais, alterar arquivos ou injetar código no GLPI.', 'healthcheck'))
            ->cause(__('Uso de "chmod 777" ou "chmod -R" amplo para contornar problemas de permissão.', 'healthcheck'))
            ->recommendation(__('Restrinja as permissões: diretórios de dados 750/755, arquivos 640/644 e segredos 600/640, com dono correto.', 'healthcheck'))
            ->steps([
                __('Diretórios: "find /caminho/do/glpi -type d -perm -0002 -exec chmod o-w {} \\;".', 'healthcheck'),
                __('Segredos: "chmod 640 config/config_db.php config/glpicrypt.key" e "chown root:www-data" nesses arquivos.', 'healthcheck'),
                __('Código: mantenha src, inc e vendor pertencentes a root (somente leitura para o servidor web).', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->items($items, [
                'target'  => __('Item', 'healthcheck'),
                'path'    => __('Caminho', 'healthcheck'),
                'mode'    => __('Permissões', 'healthcheck'),
                'problem' => __('Problema', 'healthcheck'),
            ]);
    }
}
