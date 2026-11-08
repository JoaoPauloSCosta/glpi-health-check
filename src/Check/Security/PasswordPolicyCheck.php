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

class PasswordPolicyCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'security.password_policy';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Política de senhas locais', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $enabled    = (int) $this->cfg('use_password_security', 0) === 1;
        $min_length = (int) $this->cfg('password_min_length', 8);

        $local_users = $this->countRows('glpi_users', [
            'authtype'   => \Auth::DB_GLPI,
            'is_active'  => 1,
            'is_deleted' => 0,
        ]);

        $facts = [
            __('Política de senha ativa', 'healthcheck')        => $enabled,
            __('Tamanho mínimo', 'healthcheck')                 => $min_length,
            __('Exige número', 'healthcheck')                   => (bool) $this->cfg('password_need_number', 0),
            __('Exige letra minúscula', 'healthcheck')          => (bool) $this->cfg('password_need_letter', 0),
            __('Exige letra maiúscula', 'healthcheck')          => (bool) $this->cfg('password_need_caps', 0),
            __('Exige símbolo', 'healthcheck')                  => (bool) $this->cfg('password_need_symbol', 0),
            __('Usuários ativos com autenticação local', 'healthcheck') => $local_users,
        ];

        if ($local_users === 0) {
            return CheckResult::info(__('Nenhum usuário ativo usa autenticação local; as senhas são gerenciadas por LDAP/SSO.', 'healthcheck'))
                ->facts($facts);
        }

        $findings = [];
        $problems = [];
        if (!$enabled) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
            $problems[] = __('A política de segurança de senhas está desativada: qualquer senha é aceita.', 'healthcheck');
        } elseif ($min_length < 8) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
            $problems[] = sprintf(__('Tamanho mínimo de senha muito baixo (%d caracteres).', 'healthcheck'), $min_length);
        }

        if (count($findings) === 0) {
            return CheckResult::ok(__('A política de senhas locais está ativa e adequada.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf($findings, '', implode(' ', $problems))
            ->impact(__('Senhas fracas facilitam ataques de força bruta e reutilização de credenciais vazadas.', 'healthcheck'))
            ->cause(__('Configuração padrão da instalação.', 'healthcheck'))
            ->recommendation(__('Ative a política de senhas com no mínimo 12 caracteres, ou centralize a autenticação em LDAP/SSO.', 'healthcheck'))
            ->steps([
                __('Acesse Configurar > Geral > Segurança.', 'healthcheck'),
                __('Ative "Política de segurança de senha" e defina o tamanho mínimo.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts);
    }
}
