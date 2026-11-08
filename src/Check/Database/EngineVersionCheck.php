<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Database;

use Glpi\System\Requirement\DbEngine;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

class EngineVersionCheck extends AbstractCheck
{
    /**
     * Known end of community support dates (only well established branches).
     */
    private const EOL_DATES = [
        'MySQL' => [
            '5.7' => '2023-10-31',
            '8.0' => '2026-04-30',
            '8.4' => '2032-04-30',
        ],
        'MariaDB' => [
            '10.2'  => '2022-05-23',
            '10.3'  => '2023-05-25',
            '10.4'  => '2024-06-18',
            '10.5'  => '2025-06-24',
            '10.6'  => '2026-07-06',
            '10.7'  => '2023-02-14',
            '10.8'  => '2023-05-20',
            '10.9'  => '2023-08-22',
            '10.10' => '2023-11-17',
            '10.11' => '2028-02-16',
            '11.0'  => '2024-06-06',
            '11.1'  => '2024-08-21',
            '11.2'  => '2024-11-21',
            '11.3'  => '2025-05-29',
            '11.4'  => '2029-05-29',
        ],
    ];

    public function getKey(): string
    {
        return 'database.engine_version';
    }

    public function getCategory(): string
    {
        return Category::DATABASE;
    }

    public function getTitle(): string
    {
        return __('Versão e compatibilidade do MySQL/MariaDB', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $version_string = (string) $this->db()->getVersion();
        $server  = preg_match('/-MariaDB/i', $version_string) ? 'MariaDB' : 'MySQL';
        $version = preg_replace('/^((\d+\.?)+).*$/', '$1', $version_string);
        $parts   = explode('.', $version);
        $branch  = ($parts[0] ?? '0') . '.' . ($parts[1] ?? '0');
        $eol     = self::EOL_DATES[$server][$branch] ?? null;

        $facts = [
            __('Servidor', 'healthcheck')                   => $server,
            __('Versão', 'healthcheck')                     => $version_string,
            __('Fim do suporte da versão', 'healthcheck')   => $eol ?? __('Desconhecido', 'healthcheck'),
        ];

        $requirement = new DbEngine($this->db());
        if (!$requirement->isValidated()) {
            return CheckResult::critical(implode(' ', $requirement->getValidationMessages()), Severity::CRITICAL)
                ->impact(__('Versões de banco não suportadas causam erros de SQL e impedem atualizações do GLPI.', 'healthcheck'))
                ->recommendation(__('Atualize o servidor de banco para uma versão suportada (MySQL >= 5.7 ou MariaDB >= 10.2, preferencialmente uma versão LTS atual).', 'healthcheck'))
                ->steps([
                    __('Faça backup completo (dump) do banco de dados.', 'healthcheck'),
                    __('Atualize o servidor MySQL/MariaDB seguindo a documentação do fornecedor.', 'healthcheck'),
                    __('Execute "mysql_upgrade"/"mariadb-upgrade" quando aplicável.', 'healthcheck'),
                ])
                ->doc(self::DOC_PREREQUISITES)
                ->facts($facts);
        }

        if ($eol !== null && strtotime($eol . ' 23:59:59') < $this->now()) {
            return CheckResult::warning(
                sprintf(__('%1$s %2$s está fora do período de suporte do fornecedor desde %3$s.', 'healthcheck'), $server, $branch, $eol),
                Severity::MEDIUM
            )
                ->impact(__('Sem correções de segurança e de bugs; versões futuras do GLPI podem deixar de suportar esta versão.', 'healthcheck'))
                ->cause(__('O servidor de banco não foi atualizado junto com o sistema operacional.', 'healthcheck'))
                ->recommendation(__('Planeje a atualização para uma versão LTS suportada.', 'healthcheck'))
                ->doc(self::DOC_PREREQUISITES)
                ->facts($facts);
        }

        return CheckResult::ok(sprintf(__('%1$s %2$s é compatível com o GLPI.', 'healthcheck'), $server, $version))
            ->facts($facts);
    }
}
