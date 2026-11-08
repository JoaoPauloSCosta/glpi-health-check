<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Security;

use APIClient;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

class ApiSecurityCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'security.api';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Exposição da API REST', 'healthcheck');
    }

    public function run(): CheckResult
    {
        if (!(int) $this->cfg('enable_api', 0)) {
            return CheckResult::ok(__('A API REST está desativada.', 'healthcheck'));
        }

        $items    = [];
        $findings = [];
        $active   = 0;
        foreach ($this->db()->request(['FROM' => APIClient::getTable(), 'WHERE' => ['is_active' => 1]]) as $client) {
            $active++;
            $has_ip    = $client['ipv4_range_start'] !== null || trim((string) $client['ipv6']) !== '';
            $has_token = trim((string) $client['app_token']) !== '';
            if (!$has_ip && !$has_token) {
                $findings[] = [Status::WARNING, Severity::HIGH];
                $items[] = [
                    'client'  => $client['name'],
                    'problem' => __('Sem restrição de IP e sem App-Token: aceita requisições de qualquer origem.', 'healthcheck'),
                ];
            } elseif (!$has_token) {
                $findings[] = [Status::WARNING, Severity::LOW];
                $items[] = [
                    'client'  => $client['name'],
                    'problem' => __('Sem App-Token (somente restrição de IP).', 'healthcheck'),
                ];
            }
        }

        if ((int) $this->cfg('enable_api_login_credentials', 0)) {
            $findings[] = [Status::WARNING, Severity::LOW];
            $items[] = [
                'client'  => __('Configuração geral', 'healthcheck'),
                'problem' => __('Login com usuário e senha pela API habilitado: prefira tokens pessoais.', 'healthcheck'),
            ];
        }

        $facts = [
            __('API habilitada', 'healthcheck')       => true,
            __('Clientes da API ativos', 'healthcheck') => $active,
            __('URL da API', 'healthcheck')           => $this->cfg('url_base_api', '-'),
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('A API REST está habilitada com clientes restritos.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d ponto(s) de atenção na configuração da API REST.', 'healthcheck'), count($items))
        )
            ->impact(__('Uma API aberta permite tentativas de autenticação e acesso automatizado a partir de qualquer rede.', 'healthcheck'))
            ->cause(__('Cliente padrão "full access from localhost" alterado ou clientes criados sem restrições.', 'healthcheck'))
            ->recommendation(__('Restrinja cada cliente da API por faixa de IP e exija App-Token.', 'healthcheck'))
            ->steps([
                __('Acesse Configurar > Geral > API.', 'healthcheck'),
                __('Abra cada cliente listado e defina a faixa de IPs permitida e um App-Token.', 'healthcheck'),
                __('Desative "Habilitar login com credenciais" se não for necessário.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'client'  => __('Cliente', 'healthcheck'),
                'problem' => __('Problema', 'healthcheck'),
            ]);
    }
}
