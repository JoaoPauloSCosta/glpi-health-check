<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Database;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\Format;

/**
 * Table level inconsistencies detectable from information_schema (no table scan).
 */
class TablesStatusCheck extends AbstractCheck
{
    /** int unsigned max value */
    private const UINT_MAX = 4294967295;

    public function getKey(): string
    {
        return 'database.tables';
    }

    public function getCategory(): string
    {
        return Category::DATABASE;
    }

    public function getTitle(): string
    {
        return __('Estado das tabelas', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Tabelas inacessíveis ou marcadas como corrompidas, tabelas sem chave primária e contadores AUTO_INCREMENT próximos do limite.', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db = $this->db();
        $tables = $db->request([
            'SELECT' => ['TABLE_NAME', 'ENGINE', 'TABLE_COMMENT', 'AUTO_INCREMENT'],
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => [
                'TABLE_SCHEMA' => $db->dbdefault,
                'TABLE_TYPE'   => 'BASE TABLE',
                'TABLE_NAME'   => ['LIKE', 'glpi\_%'],
            ],
        ]);

        $with_pk = [];
        foreach ($db->request([
            'SELECT' => ['TABLE_NAME'],
            'FROM'   => 'information_schema.TABLE_CONSTRAINTS',
            'WHERE'  => [
                'TABLE_SCHEMA'    => $db->dbdefault,
                'CONSTRAINT_TYPE' => 'PRIMARY KEY',
            ],
        ]) as $row) {
            $with_pk[$row['TABLE_NAME']] = true;
        }

        $items    = [];
        $findings = [];
        $count    = 0;
        foreach ($tables as $table) {
            $count++;
            $name    = $table['TABLE_NAME'];
            $comment = (string) ($table['TABLE_COMMENT'] ?? '');

            if ($table['ENGINE'] === null || stripos($comment, 'crashed') !== false || stripos($comment, 'corrupt') !== false) {
                $findings[] = [Status::CRITICAL, Severity::HIGH];
                $items[] = [
                    'table'   => $name,
                    'problem' => __('Tabela inacessível ou marcada como corrompida', 'healthcheck'),
                    'details' => $comment !== '' ? $comment : __('Motor de armazenamento indisponível', 'healthcheck'),
                ];
                continue;
            }

            if (!isset($with_pk[$name])) {
                $findings[] = [Status::WARNING, Severity::LOW];
                $items[] = [
                    'table'   => $name,
                    'problem' => __('Tabela sem chave primária', 'healthcheck'),
                    'details' => __('Pode causar problemas de replicação e de desempenho.', 'healthcheck'),
                ];
            }

            $auto_increment = (int) ($table['AUTO_INCREMENT'] ?? 0);
            if ($auto_increment > self::UINT_MAX * 0.8) {
                $findings[] = [Status::WARNING, Severity::HIGH];
                $items[] = [
                    'table'   => $name,
                    'problem' => __('AUTO_INCREMENT próximo do limite', 'healthcheck'),
                    'details' => sprintf(
                        __('%s%% do valor máximo utilizado; novas inserções falharão ao atingir o limite.', 'healthcheck'),
                        Format::number(Format::percent($auto_increment, self::UINT_MAX), 1)
                    ),
                ];
            }
        }

        $facts = [__('Tabelas analisadas', 'healthcheck') => $count];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma inconsistência encontrada nas tabelas.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d problema(s) encontrado(s) nas tabelas do banco.', 'healthcheck'), count($items))
        )
            ->impact(__('Tabelas corrompidas causam perda de dados e erros; contadores esgotados impedem novos registros.', 'healthcheck'))
            ->cause(__('Desligamento abrupto do servidor, falha de disco, tabelas MyISAM ou volume muito alto de inserções (ex.: logs).', 'healthcheck'))
            ->recommendation(__('Faça backup imediatamente e analise as tabelas indicadas.', 'healthcheck'))
            ->steps([
                __('Faça um dump completo do banco de dados.', 'healthcheck'),
                __('Para tabelas corrompidas, execute "CHECK TABLE nome_da_tabela" e, se necessário, "REPAIR TABLE" (MyISAM) ou restaure do backup (InnoDB).', 'healthcheck'),
                __('Para AUTO_INCREMENT próximo do limite, purgue registros antigos e avalie com o suporte da comunidade antes de alterar o tipo da coluna.', 'healthcheck'),
            ])
            ->doc(self::DOC_CLI)
            ->facts($facts)
            ->items($items, [
                'table'   => __('Tabela', 'healthcheck'),
                'problem' => __('Problema', 'healthcheck'),
                'details' => __('Detalhes', 'healthcheck'),
            ]);
    }
}
