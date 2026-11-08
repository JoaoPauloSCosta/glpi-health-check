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
use GlpiPlugin\Healthcheck\Format;

/**
 * Relations pointing to records that no longer exist (read-only LEFT JOIN counts).
 */
class OrphanRecordsCheck extends AbstractCheck
{
    /**
     * [child table, foreign key, parent table, allow zero (0 is a valid id), extra where, description]
     */
    private function getRelations(): array
    {
        return [
            ['glpi_profiles_users', 'users_id', 'glpi_users', false, [], __('Autorizações de usuários inexistentes', 'healthcheck')],
            ['glpi_profiles_users', 'profiles_id', 'glpi_profiles', false, [], __('Autorizações com perfil inexistente', 'healthcheck')],
            ['glpi_profiles_users', 'entities_id', 'glpi_entities', true, [], __('Autorizações em entidade inexistente', 'healthcheck')],
            ['glpi_groups_users', 'users_id', 'glpi_users', false, [], __('Membros de grupo inexistentes', 'healthcheck')],
            ['glpi_groups_users', 'groups_id', 'glpi_groups', false, [], __('Vínculos com grupo inexistente', 'healthcheck')],
            ['glpi_useremails', 'users_id', 'glpi_users', false, [], __('E-mails de usuários inexistentes', 'healthcheck')],
            ['glpi_tickets_users', 'tickets_id', 'glpi_tickets', false, [], __('Atores de chamados inexistentes', 'healthcheck')],
            ['glpi_tickets_users', 'users_id', 'glpi_users', false, [], __('Chamados com usuário ator inexistente', 'healthcheck')],
            ['glpi_groups_tickets', 'tickets_id', 'glpi_tickets', false, [], __('Grupos de chamados inexistentes', 'healthcheck')],
            ['glpi_groups_tickets', 'groups_id', 'glpi_groups', false, [], __('Chamados com grupo inexistente', 'healthcheck')],
            ['glpi_tickettasks', 'tickets_id', 'glpi_tickets', false, [], __('Tarefas de chamados inexistentes', 'healthcheck')],
            ['glpi_itilfollowups', 'items_id', 'glpi_tickets', false, ['glpi_itilfollowups.itemtype' => 'Ticket'], __('Acompanhamentos de chamados inexistentes', 'healthcheck')],
            ['glpi_items_tickets', 'tickets_id', 'glpi_tickets', false, [], __('Itens vinculados a chamados inexistentes', 'healthcheck')],
            ['glpi_documents_items', 'documents_id', 'glpi_documents', false, [], __('Vínculos com documento inexistente', 'healthcheck')],
            ['glpi_tickets', 'entities_id', 'glpi_entities', true, [], __('Chamados em entidade inexistente', 'healthcheck')],
            ['glpi_computers', 'entities_id', 'glpi_entities', true, [], __('Computadores em entidade inexistente', 'healthcheck')],
            ['glpi_users', 'entities_id', 'glpi_entities', true, [], __('Usuários com entidade padrão inexistente', 'healthcheck')],
            ['glpi_rulecriterias', 'rules_id', 'glpi_rules', false, [], __('Critérios de regras inexistentes', 'healthcheck')],
            ['glpi_ruleactions', 'rules_id', 'glpi_rules', false, [], __('Ações de regras inexistentes', 'healthcheck')],
        ];
    }

    public function getKey(): string
    {
        return 'integrity.orphans';
    }

    public function getCategory(): string
    {
        return Category::INTEGRITY;
    }

    public function getTitle(): string
    {
        return __('Registros órfãos', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $items   = [];
        $checked = 0;
        $total   = 0;
        foreach ($this->getRelations() as [$child, $fk, $parent, $allow_zero, $extra, $label]) {
            if (!$this->tableExists($child) || !$this->tableExists($parent) || !$this->fieldExists($child, $fk)) {
                continue;
            }
            $where = array_merge([$parent . '.id' => null], $extra);
            if (!$allow_zero) {
                $where[$child . '.' . $fk] = ['>', 0];
            } else {
                $where[] = ['NOT' => [$child . '.' . $fk => null]];
            }
            $row = $this->db()->request([
                'COUNT'     => 'cpt',
                'FROM'      => $child,
                'LEFT JOIN' => [
                    $parent => ['ON' => [$child => $fk, $parent => 'id']],
                ],
                'WHERE'     => $where,
            ])->current();
            $checked++;
            $count = (int) ($row['cpt'] ?? 0);
            if ($count > 0) {
                $total += $count;
                $items[] = [
                    'relation' => $label,
                    'table'    => sprintf('%s.%s → %s', $child, $fk, $parent),
                    'count'    => Format::number($count),
                ];
            }
        }

        $facts = [
            __('Relacionamentos verificados', 'healthcheck') => $checked,
            __('Registros órfãos', 'healthcheck')            => Format::number($total),
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhum registro órfão encontrado nos relacionamentos verificados.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(__('%1$s registro(s) órfão(s) em %2$d relacionamento(s).', 'healthcheck'), Format::number($total), count($items)),
            $total > 1000 ? Severity::MEDIUM : Severity::LOW
        )
            ->impact(__('Registros órfãos causam erros em listas e relatórios, contagens incorretas e podem falhar migrações de atualização.', 'healthcheck'))
            ->cause(__('Exclusões diretas no banco, falhas durante exclusões, plugins removidos ou restauração parcial de dados.', 'healthcheck'))
            ->recommendation(__('Analise os registros antes de qualquer limpeza; a correção deve ser feita com backup e preferencialmente em homologação.', 'healthcheck'))
            ->steps([
                __('Faça backup completo do banco de dados.', 'healthcheck'),
                __('Verifique se a ação automática "cleanorphans" está ativa.', 'healthcheck'),
                __('Liste os registros com uma consulta LEFT JOIN semelhante à indicada na coluna "Relacionamento" e avalie se podem ser removidos.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'relation' => __('Problema', 'healthcheck'),
                'table'    => __('Relacionamento', 'healthcheck'),
                'count'    => __('Quantidade', 'healthcheck'),
            ]);
    }
}
