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
 * Entity tree consistency (parent, level, complete name).
 */
class EntitiesCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'integrity.entities';
    }

    public function getCategory(): string
    {
        return Category::INTEGRITY;
    }

    public function getTitle(): string
    {
        return __('Consistência das entidades', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $entities = [];
        foreach ($this->db()->request([
            'SELECT' => ['id', 'name', 'entities_id', 'level', 'completename'],
            'FROM'   => 'glpi_entities',
        ]) as $row) {
            $entities[(int) $row['id']] = $row;
        }

        $items    = [];
        $findings = [];
        $names    = [];
        foreach ($entities as $id => $entity) {
            if ($id === 0) {
                continue; // root entity
            }
            $parent_id = $entity['entities_id'] === null ? null : (int) $entity['entities_id'];
            if ($parent_id === null || !isset($entities[$parent_id]) || $parent_id === $id) {
                $findings[] = [Status::WARNING, Severity::HIGH];
                $items[] = [
                    'entity'  => sprintf('%s (#%d)', $entity['completename'], $id),
                    'problem' => __('Entidade pai inexistente ou inválida', 'healthcheck'),
                ];
                continue;
            }
            $expected_level = (int) $entities[$parent_id]['level'] + 1;
            if ((int) $entity['level'] !== $expected_level) {
                $findings[] = [Status::WARNING, Severity::MEDIUM];
                $items[] = [
                    'entity'  => sprintf('%s (#%d)', $entity['completename'], $id),
                    'problem' => sprintf(__('Nível %1$d inconsistente (esperado %2$d)', 'healthcheck'), (int) $entity['level'], $expected_level),
                ];
            }
            $key = mb_strtolower((string) $entity['completename']);
            if (isset($names[$key])) {
                $findings[] = [Status::WARNING, Severity::LOW];
                $items[] = [
                    'entity'  => sprintf('%s (#%d, #%d)', $entity['completename'], $names[$key], $id),
                    'problem' => __('Nome completo duplicado', 'healthcheck'),
                ];
            } else {
                $names[$key] = $id;
            }
        }

        // Cycles in the tree
        foreach ($entities as $id => $entity) {
            $seen = [];
            $current = $id;
            while ($current !== 0 && isset($entities[$current])) {
                if (isset($seen[$current])) {
                    $findings[] = [Status::CRITICAL, Severity::HIGH];
                    $items[] = [
                        'entity'  => sprintf('%s (#%d)', $entity['completename'], $id),
                        'problem' => __('Ciclo na árvore de entidades', 'healthcheck'),
                    ];
                    break;
                }
                $seen[$current] = true;
                $parent = $entities[$current]['entities_id'];
                if ($parent === null) {
                    break;
                }
                $current = (int) $parent;
            }
        }

        $facts = [__('Entidades', 'healthcheck') => count($entities)];

        if (count($items) === 0) {
            return CheckResult::ok(__('A árvore de entidades está consistente.', 'healthcheck'))->facts($facts);
        }

        return CheckResult::worstOf($findings, '', sprintf(__('%d inconsistência(s) na árvore de entidades.', 'healthcheck'), count($items)))
            ->impact(__('Entidades inconsistentes provocam erros de visibilidade (itens que somem ou aparecem para quem não deveria) e falhas em regras e relatórios.', 'healthcheck'))
            ->cause(__('Alterações diretas no banco, importações ou exclusões interrompidas.', 'healthcheck'))
            ->recommendation(__('Corrija a entidade pai pela interface e, após backup, regenere os caches da árvore.', 'healthcheck'))
            ->steps([
                __('Em Administração > Entidades, abra cada entidade listada e redefina a entidade pai.', 'healthcheck'),
                __('Limpe o cache do GLPI: "php bin/console cache:clear".', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'entity'  => __('Entidade', 'healthcheck'),
                'problem' => __('Problema', 'healthcheck'),
            ]);
    }
}
