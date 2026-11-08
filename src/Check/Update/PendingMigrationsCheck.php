<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Update;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

/**
 * Optional database migrations provided by GLPI (bin/console migration:*).
 */
class PendingMigrationsCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'update.pending_migrations';
    }

    public function getCategory(): string
    {
        return Category::UPDATE;
    }

    public function getTitle(): string
    {
        return __('Pendências para futuras atualizações', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Migrações estruturais do banco disponibilizadas pelo GLPI e ainda não aplicadas (InnoDB, utf8mb4, timestamps, chaves sem sinal, formato de linha).', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db = $this->db();

        $checks = [
            [
                'label'   => __('Tabelas com motor MyISAM (obsoleto)', 'healthcheck'),
                'count'   => $db->getMyIsamTables()->count(),
                'command' => 'php bin/console migration:myisam_to_innodb',
            ],
            [
                'label'   => __('Tabelas com charset utf8mb3 (obsoleto)', 'healthcheck'),
                'count'   => $db->getNonUtf8mb4Tables(true)->count(),
                'command' => 'php bin/console migration:utf8mb4',
            ],
            [
                'label'   => __('Colunas do tipo datetime (sem suporte a fuso horário)', 'healthcheck'),
                'count'   => $db->getTzIncompatibleTables()->count(),
                'command' => 'php bin/console migration:timestamps',
            ],
            [
                'label'   => __('Chaves primárias/estrangeiras com inteiros com sinal', 'healthcheck'),
                'count'   => $db->getSignedKeysColumns(true)->count(),
                'command' => 'php bin/console migration:unsigned_keys',
            ],
            [
                'label'   => __('Tabelas sem ROW_FORMAT=DYNAMIC', 'healthcheck'),
                'count'   => $db->listTables('glpi\_%', ['row_format' => ['COMPACT', 'REDUNDANT']])->count(),
                'command' => 'php bin/console migration:dynamic_row_format',
            ],
        ];

        $items = [];
        foreach ($checks as $check) {
            if ($check['count'] > 0) {
                $items[] = [
                    'migration' => $check['label'],
                    'count'     => $check['count'],
                    'command'   => $check['command'],
                ];
            }
        }

        // Plugins tables still using utf8mb3 (informational, depends on plugin authors)
        $plugin_non_utf8mb4 = $db->getNonUtf8mb4Tables(false)->count() - $db->getNonUtf8mb4Tables(true)->count();

        $facts = [
            __('Tabelas de plugins ainda em utf8mb3', 'healthcheck') => max(0, $plugin_non_utf8mb4),
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('Nenhuma migração estrutural pendente nas tabelas do GLPI.', 'healthcheck'))
                ->facts($facts);
        }

        $has_myisam = $checks[0]['count'] > 0;

        return CheckResult::warning(
            sprintf(__('%d tipo(s) de migração estrutural pendente(s) no banco de dados.', 'healthcheck'), count($items)),
            $has_myisam ? Severity::HIGH : Severity::MEDIUM
        )
            ->impact(__('Versões futuras do GLPI podem exigir essas migrações antes da atualização. Tabelas MyISAM não são transacionais e podem corromper dados em caso de falha; utf8mb3 não armazena emojis e alguns caracteres.', 'healthcheck'))
            ->cause(__('O banco foi criado por uma versão antiga do GLPI e as migrações opcionais nunca foram executadas.', 'healthcheck'))
            ->recommendation(__('Execute as migrações indicadas, em janela de manutenção, após backup completo.', 'healthcheck'))
            ->steps([
                __('Faça backup completo do banco de dados.', 'healthcheck'),
                __('Coloque o GLPI em modo de manutenção.', 'healthcheck'),
                __('Execute cada comando listado na tabela, a partir do diretório raiz do GLPI.', 'healthcheck'),
                __('Execute este diagnóstico novamente para confirmar.', 'healthcheck'),
            ])
            ->doc(self::DOC_CLI)
            ->facts($facts)
            ->items($items, [
                'migration' => __('Pendência', 'healthcheck'),
                'count'     => __('Quantidade', 'healthcheck'),
                'command'   => __('Comando', 'healthcheck'),
            ]);
    }
}
