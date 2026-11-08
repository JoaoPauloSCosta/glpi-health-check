<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Update;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

class DbSchemaVersionCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'update.db_version';
    }

    public function getCategory(): string
    {
        return Category::UPDATE;
    }

    public function getTitle(): string
    {
        return __('Versão do esquema do banco de dados', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db_version  = trim((string) ($this->cfg('dbversion') ?? $this->cfg('version') ?? ''));
        $expected    = GLPI_SCHEMA_VERSION;

        $facts = [
            __('Versão dos arquivos', 'healthcheck')        => GLPI_VERSION,
            __('Versão registrada no banco', 'healthcheck') => $db_version !== '' ? $db_version : null,
            __('Versão esperada do esquema', 'healthcheck') => $expected,
        ];

        if ($db_version === '') {
            return CheckResult::skipped(__('A versão do esquema não está registrada na configuração.', 'healthcheck'))
                ->facts($facts);
        }

        if ($db_version !== $expected) {
            return CheckResult::critical(
                __('A versão do banco de dados não corresponde à versão dos arquivos do GLPI.', 'healthcheck'),
                Severity::CRITICAL
            )
                ->impact(__('O GLPI bloqueia o acesso até que a atualização do banco seja concluída; dados podem ficar inconsistentes.', 'healthcheck'))
                ->cause(__('Os arquivos foram atualizados mas a migração do banco não foi executada (ou foi interrompida).', 'healthcheck'))
                ->recommendation(__('Conclua a atualização do banco de dados.', 'healthcheck'))
                ->steps([
                    __('Faça backup do banco de dados.', 'healthcheck'),
                    __('Execute "php bin/console db:update" no diretório do GLPI.', 'healthcheck'),
                    __('Verifique os logs em files/_log caso a atualização falhe.', 'healthcheck'),
                ])
                ->doc(self::DOC_UPDATE)
                ->facts($facts);
        }

        return CheckResult::ok(__('O esquema do banco de dados está na versão esperada.', 'healthcheck'))
            ->facts($facts);
    }
}
