<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Integrity;

use Document;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Format;

/**
 * Documents registered in database whose file is missing on disk (sample of the most recent ones).
 */
class DocumentFilesCheck extends AbstractCheck
{
    private const SAMPLE = 2000;

    public function getKey(): string
    {
        return 'integrity.documents';
    }

    public function getCategory(): string
    {
        return Category::INTEGRITY;
    }

    public function getTitle(): string
    {
        return __('Documentos sem arquivo físico', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        if (!is_dir(GLPI_DOC_DIR) || !is_readable(GLPI_DOC_DIR)) {
            return CheckResult::skipped(__('O diretório de documentos não pode ser lido.', 'healthcheck'));
        }

        $total = $this->countRows(Document::getTable(), ['is_deleted' => 0, 'NOT' => ['filepath' => null], ['filepath' => ['<>', '']]]);
        if ($total === 0) {
            return CheckResult::info(__('Nenhum documento com arquivo cadastrado.', 'healthcheck'));
        }

        $missing = [];
        $checked = 0;
        foreach ($this->db()->request([
            'SELECT' => ['id', 'name', 'filename', 'filepath', 'date_creation'],
            'FROM'   => Document::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'NOT' => ['filepath' => null], ['filepath' => ['<>', '']]],
            'ORDER'  => 'id DESC',
            'LIMIT'  => self::SAMPLE,
        ]) as $doc) {
            $checked++;
            if (!is_file(GLPI_DOC_DIR . '/' . $doc['filepath'])) {
                $missing[] = [
                    'document' => sprintf('%s (#%d)', $doc['name'] ?: $doc['filename'], $doc['id']),
                    'path'     => $doc['filepath'],
                    'date'     => $doc['date_creation'],
                ];
            }
        }

        $facts = [
            __('Documentos com arquivo', 'healthcheck')     => Format::number($total),
            __('Verificados (mais recentes)', 'healthcheck') => Format::number($checked),
            __('Arquivos ausentes', 'healthcheck')           => count($missing),
        ];

        if (count($missing) === 0) {
            return CheckResult::ok(sprintf(__('Os %d documentos mais recentes possuem arquivo físico.', 'healthcheck'), $checked))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(__('%1$d de %2$d documento(s) verificado(s) sem arquivo físico.', 'healthcheck'), count($missing), $checked),
            count($missing) > 10 ? Severity::HIGH : Severity::MEDIUM
        )
            ->impact(__('Usuários recebem erro ao abrir anexos de chamados e ativos; pode indicar perda de dados.', 'healthcheck'))
            ->cause(__('Restauração do banco sem os arquivos, migração de servidor incompleta, limpeza manual de files/ ou disco cheio no momento do upload.', 'healthcheck'))
            ->recommendation(__('Restaure os arquivos ausentes a partir do backup do diretório de documentos.', 'healthcheck'))
            ->steps([
                __('Execute "php bin/console diagnostic:check_documents_integrity" para uma verificação completa.', 'healthcheck'),
                __('Restaure os arquivos do backup para os caminhos indicados em files/.', 'healthcheck'),
            ])
            ->doc(self::DOC_CLI)
            ->facts($facts)
            ->items($missing, [
                'document' => __('Documento', 'healthcheck'),
                'path'     => __('Caminho esperado', 'healthcheck'),
                'date'     => __('Criado em', 'healthcheck'),
            ]);
    }
}
