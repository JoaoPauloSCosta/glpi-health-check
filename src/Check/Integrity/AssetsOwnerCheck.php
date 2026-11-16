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

class AssetsOwnerCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'integrity.assets_owner';
    }

    public function getCategory(): string
    {
        return Category::INTEGRITY;
    }

    public function getTitle(): string
    {
        return __('Ativos sem responsáveis', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $types = [
            'Computer'         => __('Computadores', 'healthcheck'),
            'Monitor'          => __('Monitores', 'healthcheck'),
            'NetworkEquipment' => __('Equipamentos de rede', 'healthcheck'),
            'Printer'          => __('Impressoras', 'healthcheck'),
            'Phone'            => __('Telefones', 'healthcheck'),
            'Peripheral'       => __('Dispositivos', 'healthcheck'),
        ];
        $owner_fields = ['users_id', 'groups_id', 'users_id_tech', 'groups_id_tech'];

        $items = [];
        $total_assets = 0;
        $total_orphans = 0;
        foreach ($types as $itemtype => $label) {
            $table = getTableForItemType($itemtype);
            if (!$this->tableExists($table)) {
                continue;
            }
            $base = ['is_deleted' => 0];
            if ($this->fieldExists($table, 'is_template')) {
                $base['is_template'] = 0;
            }
            $total = $this->countRows($table, $base);
            if ($total === 0) {
                continue;
            }
            $where = $base;
            foreach ($owner_fields as $field) {
                if ($this->fieldExists($table, $field)) {
                    $where[$field] = 0;
                }
            }
            $without = $this->countRows($table, $where);
            if ($without > 0 && !$this->fieldExists($table, 'groups_id')) {
                // GLPI 11+: owner and technical groups are linked through glpi_groups_items
                $without = max(0, $without - $this->countWithGroup($itemtype, $table, $where));
            }
            $total_assets  += $total;
            $total_orphans += $without;
            if ($without > 0) {
                $items[] = [
                    'type'    => $label,
                    'total'   => Format::number($total),
                    'without' => Format::number($without),
                    'pct'     => Format::number(Format::percent($without, $total), 1) . '%',
                ];
            }
        }

        $facts = [
            __('Ativos analisados', 'healthcheck')       => Format::number($total_assets),
            __('Sem usuário, grupo, técnico ou grupo técnico', 'healthcheck') => Format::number($total_orphans),
        ];

        if ($total_assets === 0) {
            return CheckResult::info(__('Nenhum ativo cadastrado.', 'healthcheck'));
        }
        if (count($items) === 0) {
            return CheckResult::ok(__('Todos os ativos têm algum responsável definido.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(__('%1$s de %2$s ativo(s) sem nenhum responsável (usuário, grupo, técnico ou grupo técnico).', 'healthcheck'), Format::number($total_orphans), Format::number($total_assets)),
            Severity::LOW
        )
            ->impact(__('Sem responsável, chamados sobre o ativo não são atribuídos automaticamente e o controle patrimonial fica comprometido.', 'healthcheck'))
            ->cause(__('Ativos criados por inventário automático sem regras de atribuição, ou cadastros incompletos.', 'healthcheck'))
            ->recommendation(__('Defina responsáveis em massa ou por regras de importação/negócio.', 'healthcheck'))
            ->steps([
                __('Na lista do tipo de ativo, filtre por "Técnico responsável" vazio e "Usuário" vazio.', 'healthcheck'),
                __('Use a ação em massa "Atualizar" para definir o grupo técnico responsável.', 'healthcheck'),
                __('Configure regras de negócio para ativos para atribuição automática.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'type'    => __('Tipo', 'healthcheck'),
                'total'   => __('Total', 'healthcheck'),
                'without' => __('Sem responsável', 'healthcheck'),
                'pct'     => __('%', 'healthcheck'),
            ]);
    }

    /**
     * Number of assets matching the given criteria that are linked to at least one group
     * through the glpi_groups_items table (GLPI 11+).
     */
    private function countWithGroup(string $itemtype, string $table, array $where): int
    {
        $link = 'glpi_groups_items';
        if (!$this->tableExists($link)) {
            return 0;
        }

        $db = $this->db();
        $criteria = [];
        foreach ($where as $field => $value) {
            $criteria[$table . '.' . $field] = $value;
        }
        $criteria[$link . '.itemtype'] = $itemtype;

        $row = $db->request([
            'SELECT'     => [$this->expr('COUNT(DISTINCT ' . $db->quoteName($table . '.id') . ') AS cpt')],
            'FROM'       => $table,
            'INNER JOIN' => [
                $link => ['ON' => [$table => 'id', $link => 'items_id']],
            ],
            'WHERE'      => $criteria,
        ])->current();

        return (int) ($row['cpt'] ?? 0);
    }
}
