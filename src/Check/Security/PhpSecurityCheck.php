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
use Session;
use User;

/**
 * Insecure PHP and GLPI defaults.
 */
class PhpSecurityCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'security.insecure_settings';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Configurações padrão inseguras', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $items    = [];
        $findings = [];
        $add = function (string $setting, string $value, string $problem, int $severity) use (&$items, &$findings) {
            $findings[] = [Status::WARNING, $severity];
            $items[] = ['setting' => $setting, 'value' => $value, 'problem' => $problem];
        };

        $is_on = static fn (string $directive): bool => filter_var(ini_get($directive), FILTER_VALIDATE_BOOLEAN);

        if (!$this->isCli() && filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN)) {
            $add('display_errors', (string) ini_get('display_errors'), __('Erros do PHP exibidos aos usuários revelam caminhos, consultas e detalhes internos.', 'healthcheck'), Severity::MEDIUM);
        }
        if ($is_on('expose_php') && !$this->isCli()) {
            $add('expose_php', 'On', __('Anuncia a versão do PHP nos cabeçalhos HTTP, facilitando a escolha de ataques.', 'healthcheck'), Severity::LOW);
        }
        if ($is_on('allow_url_include')) {
            $add('allow_url_include', 'On', __('Permite incluir código PHP remoto: risco grave de execução de código.', 'healthcheck'), Severity::HIGH);
        }

        // Users in debug mode see SQL queries and internal data
        $debug_users = $this->countRows(User::getTable(), [
            'use_mode'   => Session::DEBUG_MODE,
            'is_active'  => 1,
            'is_deleted' => 0,
        ]);
        if ($debug_users > 0) {
            $add(__('Modo debug do GLPI', 'healthcheck'), (string) $debug_users, __('Usuários com modo debug ativo veem consultas SQL e dados internos, e as páginas ficam mais lentas.', 'healthcheck'), Severity::LOW);
        }

        if ((int) $this->cfg('maintenance_mode', 0) === 1) {
            $add(__('Modo de manutenção', 'healthcheck'), __('Ativo', 'healthcheck'), __('O GLPI está em manutenção: apenas super-administradores acessam a interface.', 'healthcheck'), Severity::MEDIUM);
        }

        $facts = [
            'display_errors'    => ini_get('display_errors') ?: '0',
            'expose_php'        => ini_get('expose_php') ?: '0',
            'allow_url_include' => ini_get('allow_url_include') ?: '0',
            __('Contexto', 'healthcheck') => $this->isCli() ? 'CLI' : PHP_SAPI,
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma configuração padrão insegura foi encontrada.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d configuração(ões) insegura(s) encontrada(s).', 'healthcheck'), count($items))
        )
            ->impact(__('Configurações de desenvolvimento em produção facilitam ataques e expõem informações internas.', 'healthcheck'))
            ->cause(__('php.ini de desenvolvimento em uso ou ajustes temporários de depuração não revertidos.', 'healthcheck'))
            ->recommendation(__('Use o php.ini de produção e desative recursos de depuração após o uso.', 'healthcheck'))
            ->steps([
                __('No php.ini: display_errors = Off, expose_php = Off, allow_url_include = Off.', 'healthcheck'),
                __('Para usuários em modo debug: Administração > Usuários > (usuário) > Configurações > Modo de uso "Normal".', 'healthcheck'),
                __('Para sair do modo de manutenção: "php bin/console maintenance:disable".', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->facts($facts)
            ->items($items, [
                'setting' => __('Configuração', 'healthcheck'),
                'value'   => __('Valor', 'healthcheck'),
                'problem' => __('Risco', 'healthcheck'),
            ]);
    }
}
