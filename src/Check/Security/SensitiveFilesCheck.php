<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Security;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\Format;

/**
 * Leftover files that should not exist in the web application directory.
 */
class SensitiveFilesCheck extends AbstractCheck
{
    private const DIAGNOSTIC_FILES = ['phpinfo.php', 'info.php', 'test.php', 'php_info.php', 'adminer.php'];
    private const BACKUP_PATTERNS  = ['*.sql', '*.sql.gz', '*.zip', '*.tar', '*.tar.gz', '*.tgz', '*.bak', '*.old', '*.dump'];

    public function getKey(): string
    {
        return 'security.sensitive_files';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Arquivos e diretórios sensíveis expostos', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $items    = [];
        $findings = [];
        $add = function (string $path, string $problem, int $severity) use (&$items, &$findings) {
            $findings[] = [Status::WARNING, $severity];
            $items[] = [
                'path'    => str_replace('\\', '/', $path),
                'size'    => is_file($path) ? Format::bytes((float) filesize($path)) : '-',
                'problem' => $problem,
            ];
        };

        if (file_exists(GLPI_ROOT . '/install/install.php')) {
            $add(GLPI_ROOT . '/install/install.php', __('Script de instalação presente: o GLPI recomenda removê-lo após a instalação.', 'healthcheck'), Severity::HIGH);
        }

        $web_dirs = array_unique([GLPI_ROOT, GLPI_ROOT . '/public']);
        foreach ($web_dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (self::DIAGNOSTIC_FILES as $file) {
                if (is_file($dir . '/' . $file)) {
                    $add($dir . '/' . $file, __('Script de diagnóstico/administração acessível: pode revelar configuração do servidor ou dar acesso ao banco.', 'healthcheck'), Severity::HIGH);
                }
            }
            foreach (self::BACKUP_PATTERNS as $pattern) {
                foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
                    $add($file, __('Backup ou dump no diretório da aplicação: pode ser baixado se estiver ao alcance do servidor web.', 'healthcheck'), Severity::HIGH);
                }
            }
        }

        foreach (['bak', 'old', 'orig', 'save', 'swp'] as $extension) {
            foreach (glob(GLPI_CONFIG_DIR . '/*.' . $extension) ?: [] as $file) {
                $add($file, __('Cópia de arquivo de configuração: pode conter credenciais.', 'healthcheck'), Severity::MEDIUM);
            }
        }

        foreach (['.git', '.svn', '.env'] as $vcs) {
            if (file_exists(GLPI_ROOT . '/' . $vcs)) {
                $add(GLPI_ROOT . '/' . $vcs, __('Metadados de versionamento/ambiente no diretório da aplicação: podem expor código e segredos se acessíveis pela web.', 'healthcheck'), Severity::MEDIUM);
            }
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhum arquivo sensível esquecido foi encontrado no diretório da aplicação.', 'healthcheck'));
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d arquivo(s) ou diretório(s) sensível(is) encontrado(s) no diretório da aplicação.', 'healthcheck'), count($items))
        )
            ->impact(__('Arquivos esquecidos podem permitir reinstalação, revelar a configuração do servidor ou vazar dados e credenciais.', 'healthcheck'))
            ->cause(__('Arquivos deixados após instalação, testes, backups manuais ou deploy via repositório.', 'healthcheck'))
            ->recommendation(__('Remova os arquivos listados ou mova-os para fora do diretório servido pelo servidor web.', 'healthcheck'))
            ->steps([
                __('Remova install/install.php após concluir a instalação/atualização.', 'healthcheck'),
                __('Mova backups e dumps para um diretório fora da raiz web, com acesso restrito.', 'healthcheck'),
                __('Configure o servidor web com a raiz em "public/" (GLPI 10.0.7+), o que bloqueia o acesso direto aos demais arquivos.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->items($items, [
                'path'    => __('Caminho', 'healthcheck'),
                'size'    => __('Tamanho', 'healthcheck'),
                'problem' => __('Risco', 'healthcheck'),
            ]);
    }
}
