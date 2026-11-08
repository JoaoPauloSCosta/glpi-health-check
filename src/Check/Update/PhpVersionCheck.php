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

class PhpVersionCheck extends AbstractCheck
{
    /**
     * End of security support of PHP branches (https://www.php.net/supported-versions.php).
     */
    private const PHP_EOL_DATES = [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    public function getKey(): string
    {
        return 'update.php_version';
    }

    public function getCategory(): string
    {
        return Category::UPDATE;
    }

    public function getTitle(): string
    {
        return __('Compatibilidade da versão do PHP', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $eol    = self::PHP_EOL_DATES[$branch] ?? null;

        $facts = [
            __('Versão do PHP', 'healthcheck')                  => PHP_VERSION,
            __('Interface (SAPI)', 'healthcheck')               => PHP_SAPI,
            __('Mínimo suportado pelo GLPI', 'healthcheck')     => GLPI_MIN_PHP,
            __('Máximo suportado (exclusivo)', 'healthcheck')   => GLPI_MAX_PHP,
            __('Fim do suporte de segurança do PHP', 'healthcheck') => $eol ?? __('Desconhecido', 'healthcheck'),
        ];

        $sapi_note = $this->isCli()
            ? __('Observação: este diagnóstico foi executado em CLI. O PHP usado pelo servidor web pode ser diferente.', 'healthcheck')
            : '';

        if (version_compare(PHP_VERSION, GLPI_MIN_PHP, '<') || version_compare(PHP_VERSION, GLPI_MAX_PHP, '>=')) {
            return CheckResult::critical(
                sprintf(
                    __('O PHP %1$s não é suportado por esta versão do GLPI (suportado: >= %2$s e < %3$s).', 'healthcheck'),
                    PHP_VERSION,
                    GLPI_MIN_PHP,
                    GLPI_MAX_PHP
                ),
                Severity::CRITICAL
            )
                ->impact(__('Comportamentos imprevisíveis, erros fatais e falhas durante atualizações.', 'healthcheck'))
                ->cause(__('A versão do PHP foi alterada no servidor sem considerar os requisitos do GLPI.', 'healthcheck') . ' ' . $sapi_note)
                ->recommendation(__('Instale uma versão do PHP dentro da faixa suportada pela versão do GLPI em uso.', 'healthcheck'))
                ->steps([
                    __('Consulte a página de pré-requisitos da sua versão do GLPI.', 'healthcheck'),
                    __('Instale a versão do PHP adequada e as extensões exigidas.', 'healthcheck'),
                    __('Aponte o servidor web (PHP-FPM/módulo) e o cron para o mesmo binário PHP.', 'healthcheck'),
                ])
                ->doc(self::DOC_PREREQUISITES)
                ->facts($facts);
        }

        if ($eol !== null) {
            $eol_ts = strtotime($eol . ' 23:59:59');
            if ($eol_ts < $this->now()) {
                return CheckResult::warning(
                    sprintf(__('O PHP %1$s não recebe mais correções de segurança desde %2$s.', 'healthcheck'), $branch, $eol),
                    Severity::HIGH
                )
                    ->impact(__('Vulnerabilidades descobertas no PHP não serão corrigidas, expondo o servidor.', 'healthcheck'))
                    ->cause(__('O sistema operacional ou o pacote do PHP não foi atualizado.', 'healthcheck') . ' ' . $sapi_note)
                    ->recommendation(__('Atualize para um ramo do PHP com suporte ativo e compatível com o GLPI instalado.', 'healthcheck'))
                    ->steps([
                        __('Verifique a faixa de versões aceita pelo GLPI (dados abaixo).', 'healthcheck'),
                        __('Planeje a atualização do PHP junto com a do sistema operacional, se necessário.', 'healthcheck'),
                    ])
                    ->doc(self::DOC_PHP_VERSIONS)
                    ->facts($facts);
            }
            if ($eol_ts - $this->now() < 180 * DAY_TIMESTAMP) {
                return CheckResult::warning(
                    sprintf(__('O suporte de segurança do PHP %1$s termina em %2$s.', 'healthcheck'), $branch, $eol),
                    Severity::LOW
                )
                    ->impact(__('Após essa data o PHP deixará de receber correções de segurança.', 'healthcheck'))
                    ->recommendation(__('Inclua a atualização do PHP no planejamento dos próximos meses.', 'healthcheck'))
                    ->doc(self::DOC_PHP_VERSIONS)
                    ->facts($facts);
            }
        }

        return CheckResult::ok(sprintf(__('O PHP %s é compatível e possui suporte de segurança.', 'healthcheck'), PHP_VERSION))
            ->facts($facts);
    }
}
