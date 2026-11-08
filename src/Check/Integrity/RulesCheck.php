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
 * Active rules that can never work as expected.
 */
class RulesCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'integrity.rules';
    }

    public function getCategory(): string
    {
        return Category::INTEGRITY;
    }

    public function getTitle(): string
    {
        return __('Regras com possíveis problemas', 'healthcheck');
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
        $checks = [
            'glpi_rulecriterias' => [__('Regra ativa sem critérios (aplica-se a tudo ou nunca é avaliada)', 'healthcheck'), Severity::MEDIUM],
            'glpi_ruleactions'   => [__('Regra ativa sem ações (não produz efeito)', 'healthcheck'), Severity::LOW],
        ];
        foreach ($checks as $table => [$label, $severity]) {
            foreach ($db->request([
                'SELECT'    => ['glpi_rules.id', 'glpi_rules.name', 'glpi_rules.sub_type'],
                'FROM'      => 'glpi_rules',
                'LEFT JOIN' => [
                    $table => ['ON' => ['glpi_rules' => 'id', $table => 'rules_id']],
                ],
                'WHERE'     => [
                    'glpi_rules.is_active' => 1,
                    $table . '.id'         => null,
                ],
                'ORDER'     => ['glpi_rules.sub_type', 'glpi_rules.ranking'],
                'LIMIT'     => 100,
            ]) as $row) {
                // Some dictionaries/import rules legitimately have no criteria (e.g. default "catch all" rules)
                if ($table === 'glpi_rulecriterias' && $this->isCatchAllAllowed((string) $row['sub_type'])) {
                    continue;
                }
                $findings[] = [Status::WARNING, $severity];
                $items[] = [
                    'rule'    => sprintf('%s (#%d)', $row['name'], $row['id']),
                    'type'    => $row['sub_type'],
                    'problem' => $label,
                ];
            }
        }

        $facts = [__('Regras ativas', 'healthcheck') => $this->countRows('glpi_rules', ['is_active' => 1])];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma regra ativa sem critérios ou sem ações.', 'healthcheck'))->facts($facts);
        }

        return CheckResult::worstOf($findings, '', sprintf(__('%d regra(s) ativa(s) com configuração incompleta.', 'healthcheck'), count($items)))
            ->impact(__('Regras incompletas podem atribuir chamados, entidades ou perfis de forma inesperada, ou simplesmente não funcionar.', 'healthcheck'))
            ->cause(__('Regras criadas e não concluídas, ou critérios/ações removidos.', 'healthcheck'))
            ->recommendation(__('Revise as regras listadas: complete critérios e ações ou desative-as.', 'healthcheck'))
            ->steps([
                __('Acesse Administração > Regras e abra o tipo de regra indicado.', 'healthcheck'),
                __('Use "Testar" para simular a regra antes de reativá-la.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'rule'    => __('Regra', 'healthcheck'),
                'type'    => __('Tipo', 'healthcheck'),
                'problem' => __('Problema', 'healthcheck'),
            ]);
    }

    private function isCatchAllAllowed(string $sub_type): bool
    {
        // Import/location rules shipped by GLPI include "catch all" rules without criteria
        return in_array($sub_type, ['RuleImportAsset', 'RuleImportEntity', 'RuleLocation', 'RuleDefineItemtype', 'RuleAsset'], true);
    }
}
