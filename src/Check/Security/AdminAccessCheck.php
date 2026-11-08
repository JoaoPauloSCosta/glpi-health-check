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

/**
 * Who can change GLPI's general configuration ("config" right with UPDATE).
 */
class AdminAccessCheck extends AbstractCheck
{
    private const MANY_ADMINS = 10;

    public function getKey(): string
    {
        return 'security.admin_access';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Acesso administrativo', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db = $this->db();

        $admin_profiles = [];
        foreach ($db->request([
            'SELECT'     => ['glpi_profiles.id', 'glpi_profiles.name', 'glpi_profiles.interface'],
            'FROM'       => 'glpi_profilerights',
            'INNER JOIN' => [
                'glpi_profiles' => ['ON' => ['glpi_profilerights' => 'profiles_id', 'glpi_profiles' => 'id']],
            ],
            'WHERE'      => [
                'glpi_profilerights.name' => 'config',
                $this->expr($db->quoteName('glpi_profilerights.rights') . ' & ' . (int) UPDATE),
            ],
        ]) as $row) {
            $admin_profiles[(int) $row['id']] = $row['name'];
        }

        $items = [];
        $admins = [];
        if (count($admin_profiles) > 0) {
            foreach ($db->request([
                'SELECT'     => ['glpi_profiles_users.profiles_id', 'glpi_users.id', 'glpi_users.name', 'glpi_users.authtype'],
                'FROM'       => 'glpi_profiles_users',
                'INNER JOIN' => [
                    'glpi_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
                ],
                'WHERE'      => [
                    'glpi_profiles_users.profiles_id' => array_keys($admin_profiles),
                    'glpi_users.is_active'            => 1,
                    'glpi_users.is_deleted'           => 0,
                ],
            ]) as $row) {
                $admins[(int) $row['id']] = $row['name'];
                $profile = $admin_profiles[(int) $row['profiles_id']];
                $items[$profile] = $items[$profile] ?? ['profile' => $profile, 'users' => 0, 'logins' => []];
                $items[$profile]['users']++;
                if (count($items[$profile]['logins']) < 15) {
                    $items[$profile]['logins'][] = $row['name'];
                }
            }
        }
        $items = array_map(static function ($item) {
            $item['logins'] = implode(', ', $item['logins']);
            return $item;
        }, array_values($items));

        $default_glpi_active = $this->countRows('glpi_users', [
            'name'       => 'glpi',
            'authtype'   => \Auth::DB_GLPI,
            'is_active'  => 1,
            'is_deleted' => 0,
        ]) > 0;

        $facts = [
            __('Perfis com direito de alterar a configuração', 'healthcheck') => implode(', ', $admin_profiles),
            __('Usuários ativos com esses perfis', 'healthcheck')            => count($admins),
            __('Conta padrão "glpi" ativa', 'healthcheck')                   => $default_glpi_active,
        ];
        $columns = [
            'profile' => __('Perfil', 'healthcheck'),
            'users'   => __('Usuários ativos', 'healthcheck'),
            'logins'  => __('Logins (amostra)', 'healthcheck'),
        ];

        $findings = [];
        $messages = [];
        if (count($admins) > self::MANY_ADMINS) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
            $messages[] = sprintf(__('%d usuários ativos podem alterar a configuração geral do GLPI.', 'healthcheck'), count($admins));
        }
        if ($default_glpi_active) {
            $findings[] = [Status::WARNING, Severity::LOW];
            $messages[] = __('A conta padrão "glpi" (super-administrador, login conhecido) está ativa.', 'healthcheck');
        }

        if (count($findings) === 0) {
            return CheckResult::ok(sprintf(__('%d usuário(s) com acesso administrativo à configuração.', 'healthcheck'), count($admins)))
                ->facts($facts)
                ->items($items, $columns);
        }

        return CheckResult::worstOf($findings, '', implode(' ', $messages))
            ->impact(__('Quanto mais contas administrativas (e quanto mais previsíveis seus logins), maior a superfície para uso indevido e ataques de força bruta.', 'healthcheck'))
            ->cause(__('Perfis administrativos atribuídos de forma ampla ou contas padrão mantidas após a instalação.', 'healthcheck'))
            ->recommendation(__('Aplique o princípio do menor privilégio e use contas nominais para administração.', 'healthcheck'))
            ->steps([
                __('Revise em Administração > Perfis quais perfis têm o direito "Configurar > Geral" com atualização.', 'healthcheck'),
                __('Remova o perfil Super-Admin de quem não precisa dele (Administração > Usuários > Autorizações).', 'healthcheck'),
                __('Crie uma conta nominal de administrador e desative a conta "glpi".', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, $columns);
    }
}
