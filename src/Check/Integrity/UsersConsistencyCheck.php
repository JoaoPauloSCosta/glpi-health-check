<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Integrity;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * Duplicate users (same login with different authentication sources, same email on several accounts)
 * and active users that cannot log in (no authorization).
 */
class UsersConsistencyCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'integrity.users';
    }

    public function getCategory(): string
    {
        return Category::INTEGRITY;
    }

    public function getTitle(): string
    {
        return __('Usuários duplicados ou inconsistentes', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $db = $this->db();

        $items    = [];
        $findings = [];

        // Same login more than once (different auth sources)
        $duplicate_logins = 0;
        foreach ($db->request([
            'SELECT'  => ['name', $this->expr('COUNT(*) AS nb')],
            'FROM'    => 'glpi_users',
            'WHERE'   => ['is_deleted' => 0, 'NOT' => ['name' => null], ['name' => ['<>', '']]],
            'GROUPBY' => 'name',
            'HAVING'  => ['nb' => ['>', 1]],
            'ORDER'   => 'nb DESC',
            'LIMIT'   => 50,
        ]) as $row) {
            $duplicate_logins++;
            $items[] = [
                'type'  => __('Login duplicado', 'healthcheck'),
                'value' => $row['name'],
                'count' => (int) $row['nb'],
            ];
        }
        if ($duplicate_logins > 0) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
        }

        // Same email used by several active users
        $duplicate_emails = 0;
        foreach ($db->request([
            'SELECT'     => ['glpi_useremails.email', $this->expr('COUNT(DISTINCT ' . $db->quoteName('glpi_useremails.users_id') . ') AS nb')],
            'FROM'       => 'glpi_useremails',
            'INNER JOIN' => [
                'glpi_users' => ['ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => ['glpi_users.is_deleted' => 0, 'glpi_users.is_active' => 1, 'NOT' => ['glpi_useremails.email' => '']],
            'GROUPBY'    => 'glpi_useremails.email',
            'HAVING'     => ['nb' => ['>', 1]],
            'ORDER'      => 'nb DESC',
            'LIMIT'      => 50,
        ]) as $row) {
            $duplicate_emails++;
            $items[] = [
                'type'  => __('E-mail em mais de um usuário ativo', 'healthcheck'),
                'value' => $row['email'],
                'count' => (int) $row['nb'],
            ];
        }
        if ($duplicate_emails > 0) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
        }

        // Active users without any authorization
        $row = $db->request([
            'COUNT'     => 'cpt',
            'FROM'      => 'glpi_users',
            'LEFT JOIN' => [
                'glpi_profiles_users' => ['ON' => ['glpi_users' => 'id', 'glpi_profiles_users' => 'users_id']],
            ],
            'WHERE'     => [
                'glpi_users.is_active'      => 1,
                'glpi_users.is_deleted'     => 0,
                'glpi_profiles_users.id'    => null,
                // Internal "glpi-system" user never logs in
                'NOT'                       => ['glpi_users.id' => (int) $this->cfg('system_user', 0)],
            ],
        ])->current();
        $without_profile = (int) ($row['cpt'] ?? 0);
        if ($without_profile > 0) {
            $findings[] = [Status::WARNING, Severity::LOW];
            $items[] = [
                'type'  => __('Usuários ativos sem nenhuma autorização (não conseguem entrar)', 'healthcheck'),
                'value' => '-',
                'count' => $without_profile,
            ];
        }

        $facts = [
            __('Logins duplicados', 'healthcheck')        => $duplicate_logins,
            __('E-mails compartilhados', 'healthcheck')   => $duplicate_emails,
            __('Ativos sem autorização', 'healthcheck')   => $without_profile,
        ];

        if (count($findings) === 0) {
            return CheckResult::ok(__('Nenhum usuário duplicado ou sem autorização encontrado.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf($findings, '', __('Há usuários duplicados ou inconsistentes.', 'healthcheck'))
            ->impact(__('E-mails compartilhados fazem o coletor associar chamados ao usuário errado; logins duplicados geram confusão de permissões e de histórico.', 'healthcheck'))
            ->cause(__('Usuários importados de mais de uma fonte (LDAP, CSV, criação manual) ou contas genéricas compartilhando o mesmo e-mail.', 'healthcheck'))
            ->recommendation(__('Unifique as contas duplicadas e mantenha cada e-mail associado a um único usuário ativo.', 'healthcheck'))
            ->steps([
                __('Em Administração > Usuários, pesquise pelo login/e-mail listado.', 'healthcheck'),
                __('Mantenha a conta da fonte de autenticação correta e desative as demais.', 'healthcheck'),
                __('Atribua um perfil aos usuários ativos sem autorização ou desative-os.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'type'  => __('Problema', 'healthcheck'),
                'value' => __('Valor', 'healthcheck'),
                'count' => __('Ocorrências', 'healthcheck'),
            ]);
    }
}
