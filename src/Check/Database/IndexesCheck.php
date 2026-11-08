<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Database;

use Glpi\System\Diagnostic\DatabaseKeysChecker;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

/**
 * Detects foreign keys columns without index, using GLPI's own naming conventions.
 */
class IndexesCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'database.indexes';
    }

    public function getCategory(): string
    {
        return Category::DATABASE;
    }

    public function getTitle(): string
    {
        return __('Índices esperados', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Procura colunas de relacionamento sem índice, o que degrada consultas e buscas.', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $checker = new DatabaseKeysChecker($this->db());
        $tables = $this->db()->listTables('glpi\_%', [
            'NOT' => ['table_name' => ['LIKE', 'glpi\_plugin\_%']],
        ]);

        $items = [];
        $nb_tables = 0;
        foreach ($tables as $table_data) {
            $table = $table_data['TABLE_NAME'];
            $nb_tables++;
            foreach ($checker->getMissingKeys($table) as $key_name => $fields) {
                $items[] = [
                    'table'  => $table,
                    'key'    => $key_name,
                    'fields' => implode(', ', (array) $fields),
                ];
            }
        }

        $facts = [
            __('Tabelas analisadas', 'healthcheck') => $nb_tables,
            __('Índices ausentes', 'healthcheck')   => count($items),
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhum índice esperado está ausente nas tabelas do GLPI.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(__('%d índice(s) esperado(s) ausente(s).', 'healthcheck'), count($items)),
            count($items) > 10 ? Severity::MEDIUM : Severity::LOW
        )
            ->impact(__('Consultas sobre essas colunas fazem varredura completa da tabela, deixando listas, buscas e relatórios lentos.', 'healthcheck'))
            ->cause(__('Alterações manuais no banco, migrações incompletas ou restauração de estrutura antiga.', 'healthcheck'))
            ->recommendation(__('Compare com a verificação de integridade do esquema e recrie os índices ausentes em janela de manutenção, após backup.', 'healthcheck'))
            ->steps([
                __('Execute "php bin/console database:check_schema_integrity" para confirmar as diferenças.', 'healthcheck'),
                __('Crie os índices ausentes (ALTER TABLE ... ADD KEY ...) conforme o esquema de referência em install/mysql/glpi-empty.sql.', 'healthcheck'),
            ])
            ->doc(self::DOC_CLI)
            ->facts($facts)
            ->items($items, [
                'table'  => __('Tabela', 'healthcheck'),
                'key'    => __('Índice esperado', 'healthcheck'),
                'fields' => __('Colunas', 'healthcheck'),
            ]);
    }
}
