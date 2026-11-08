<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Database;

use Glpi\System\Diagnostic\DatabaseSchemaIntegrityChecker;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * Compares the effective schema with the reference schema shipped with GLPI
 * (same logic as "php bin/console database:check_schema_integrity").
 */
class SchemaIntegrityCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'database.schema_integrity';
    }

    public function getCategory(): string
    {
        return Category::DATABASE;
    }

    public function getTitle(): string
    {
        return __('Integridade do esquema do banco', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Compara tabelas, colunas e índices do banco com o esquema de referência da versão instalada.', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $installed_version = trim((string) ($this->cfg('dbversion') ?? $this->cfg('version') ?? ''));

        // Non strict comparison, ignoring optional migrations (reported by another check)
        $checker = new DatabaseSchemaIntegrityChecker($this->db(), false, true, true, true, true, true);

        if (!$checker->canCheckIntegrity($installed_version, 'core')) {
            return CheckResult::skipped(
                sprintf(__('A verificação de integridade não é suportada para a versão de esquema "%s".', 'healthcheck'), $installed_version)
            );
        }

        $differences = $checker->checkCompleteSchemaForVersion($installed_version, true, 'core');

        $labels = [
            DatabaseSchemaIntegrityChecker::RESULT_TYPE_MISSING_TABLE => __('Tabela ausente', 'healthcheck'),
            DatabaseSchemaIntegrityChecker::RESULT_TYPE_ALTERED_TABLE => __('Estrutura diferente', 'healthcheck'),
            DatabaseSchemaIntegrityChecker::RESULT_TYPE_UNKNOWN_TABLE => __('Tabela desconhecida', 'healthcheck'),
        ];

        $items    = [];
        $findings = [];
        foreach ($differences as $table => $difference) {
            switch ($difference['type']) {
                case DatabaseSchemaIntegrityChecker::RESULT_TYPE_MISSING_TABLE:
                    $findings[] = [Status::CRITICAL, Severity::HIGH];
                    break;
                case DatabaseSchemaIntegrityChecker::RESULT_TYPE_ALTERED_TABLE:
                    $findings[] = [Status::WARNING, Severity::MEDIUM];
                    break;
                default:
                    $findings[] = [Status::WARNING, Severity::LOW];
            }
            $diff = $this->summarizeDiff((string) $difference['diff']);
            $items[] = [
                'table' => $table,
                'type'  => $labels[$difference['type']] ?? $difference['type'],
                'diff'  => $diff,
            ];
        }

        $facts = [
            __('Versão do esquema verificada', 'healthcheck') => $installed_version,
            __('Tabelas com diferenças', 'healthcheck')       => count($differences),
        ];

        if (count($items) === 0) {
            return CheckResult::ok(__('O esquema do banco corresponde ao esquema de referência.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d tabela(s) diferem do esquema de referência do GLPI.', 'healthcheck'), count($items))
        )
            ->impact(__('Diferenças de esquema podem causar erros de SQL e fazer falhar futuras atualizações do GLPI.', 'healthcheck'))
            ->cause(__('Alterações manuais no banco, atualização interrompida, restauração parcial de backup ou tabelas deixadas por plugins removidos.', 'healthcheck'))
            ->recommendation(__('Analise cada diferença antes de qualquer correção. Tabelas desconhecidas normalmente são restos de plugins ou customizações.', 'healthcheck'))
            ->steps([
                __('Execute "php bin/console database:check_schema_integrity" para ver o diff completo.', 'healthcheck'),
                __('Compare com um backup recente para identificar a origem da alteração.', 'healthcheck'),
                __('Corrija as diferenças em ambiente de homologação antes de aplicá-las em produção.', 'healthcheck'),
            ])
            ->doc(self::DOC_CLI)
            ->facts($facts)
            ->items($items, [
                'table' => __('Tabela', 'healthcheck'),
                'type'  => __('Tipo', 'healthcheck'),
                'diff'  => __('Diferenças (resumo)', 'healthcheck'),
            ]);
    }

    /**
     * Keep only changed lines of the unified diff.
     */
    private function summarizeDiff(string $diff): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $diff) as $line) {
            if ($line === '' || strpos($line, '---') === 0 || strpos($line, '+++') === 0 || strpos($line, '@@') === 0) {
                continue;
            }
            if ($line[0] === '-' || $line[0] === '+') {
                $lines[] = trim($line);
            }
            if (count($lines) >= 8) {
                $lines[] = '…';
                break;
            }
        }
        return implode("\n", $lines);
    }
}
