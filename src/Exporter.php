<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use GLPIPDF;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * Report exports: JSON (integration), CSV (check list), PDF (full report).
 * Generated locally; nothing is sent anywhere.
 */
final class Exporter
{
    public const FORMATS = ['json', 'csv', 'pdf'];

    private array $run;
    private array $results;

    public function __construct(array $run)
    {
        $this->run     = $run;
        $this->results = ReportView::sortResults(RunRepository::getResults((int) $run['id']));
    }

    public function getFilename(string $format): string
    {
        $date = preg_replace('/[^0-9]/', '', (string) $this->run['date_creation']);
        return sprintf('glpi-healthcheck-%d-%s.%s', $this->run['id'], $date, $format);
    }

    public function send(string $format): void
    {
        switch ($format) {
            case 'json':
                $this->sendHeaders('application/json; charset=UTF-8', $this->getFilename('json'));
                echo $this->toJson();
                return;
            case 'csv':
                $this->sendHeaders('text/csv; charset=UTF-8', $this->getFilename('csv'));
                echo $this->toCsv();
                return;
            case 'pdf':
                $pdf = $this->toPdf();
                $pdf->Output($this->getFilename('pdf'), 'D');
                return;
        }
        throw new \InvalidArgumentException('Unknown format');
    }

    private function sendHeaders(string $content_type, string $filename): void
    {
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
    }

    public function toArray(): array
    {
        $run = $this->run;
        return [
            'report'      => [
                'generator'    => 'GLPI Health Check ' . PLUGIN_HEALTHCHECK_VERSION,
                'generated_at' => date('c'),
            ],
            'environment' => $run['environment'],
            'run'         => [
                'id'           => $run['id'],
                'date'         => $run['date_creation'],
                'date_end'     => $run['date_end'],
                'mode'         => $run['mode'],
                'trigger'      => $run['trigger_type'],
                'duration_s'   => $run['duration'],
                'score'        => $run['score'],
                'overall'      => $run['overall'],
                'counts'       => [
                    'total'    => $run['nb_total'],
                    'ok'       => $run['nb_ok'],
                    'warning'  => $run['nb_warning'],
                    'critical' => $run['nb_critical'],
                    'info'     => $run['nb_info'],
                    'skipped'  => $run['nb_skipped'],
                    'error'    => $run['nb_error'],
                ],
            ],
            'checks'      => array_values(array_map(static function (array $result) {
                $details = $result['details'];
                return [
                    'key'            => $result['check_key'],
                    'category'       => $result['category'],
                    'title'          => $result['title'],
                    'status'         => $result['status'],
                    'severity'       => $result['severity'],
                    'severity_label' => Severity::getLabel((int) $result['severity']),
                    'message'        => $result['message'],
                    'impact'         => $details['impact'] ?? '',
                    'cause'          => $details['cause'] ?? '',
                    'recommendation' => $details['recommendation'] ?? '',
                    'steps'          => $details['steps'] ?? [],
                    'doc_url'        => $details['doc_url'] ?? '',
                    'facts'          => $details['facts'] ?? [],
                    'items'          => $details['items'] ?? [],
                    'items_total'    => $details['items_total'] ?? 0,
                    'duration_s'     => (float) $result['duration'],
                ];
            }, $this->results)),
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function toCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        // UTF-8 BOM so spreadsheet tools detect the encoding
        fwrite($handle, "\xEF\xBB\xBF");
        $headers = [
            __('Data', 'healthcheck'), __('Categoria', 'healthcheck'), __('Chave', 'healthcheck'), __('Verificação', 'healthcheck'),
            __('Status', 'healthcheck'), __('Criticidade', 'healthcheck'), __('Descrição', 'healthcheck'), __('Impacto', 'healthcheck'),
            __('Possível causa', 'healthcheck'), __('Recomendação', 'healthcheck'), __('Passo a passo', 'healthcheck'), __('Documentação', 'healthcheck'),
        ];
        fputcsv($handle, $headers, ';', '"', '\\');
        foreach ($this->results as $result) {
            $details = $result['details'];
            $row = [
                $this->run['date_creation'],
                Category::getLabel($result['category']),
                $result['check_key'],
                $result['title'],
                Status::getLabel($result['status']),
                Severity::getLabel((int) $result['severity']),
                $result['message'],
                $details['impact'] ?? '',
                $details['cause'] ?? '',
                $details['recommendation'] ?? '',
                implode(' | ', $details['steps'] ?? []),
                $details['doc_url'] ?? '',
            ];
            fputcsv($handle, array_map([self::class, 'csvSafe'], $row), ';', '"', '\\');
        }
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);
        return $content;
    }

    /**
     * Prevent spreadsheet formula injection.
     */
    private static function csvSafe($value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    public function toPdf(): GLPIPDF
    {
        $title = sprintf(__('Relatório Health Check — %s', 'healthcheck'), $this->run['date_creation']);
        $pdf = new GLPIPDF(['font_size' => 9, 'margin_top' => 18], null, $title);
        $pdf->SetAuthor('GLPI Health Check');
        $pdf->writeHTML($this->buildPdfHtml(), true, false, true, false, '');
        return $pdf;
    }

    private function buildPdfHtml(): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $run = $this->run;
        $env = $run['environment'];

        $colors = [
            Status::OK       => '#2fb344',
            Status::WARNING  => '#f59f00',
            Status::CRITICAL => '#d63939',
            Status::INFO     => '#4299e1',
            Status::SKIPPED  => '#868e96',
            Status::ERROR    => '#1e293b',
        ];
        $overall_colors = [Score::OVERALL_OK => '#2fb344', Score::OVERALL_WARNING => '#f59f00', Score::OVERALL_CRITICAL => '#d63939'];

        $html  = '<h1>' . $e(__('Diagnóstico de saúde da instalação GLPI', 'healthcheck')) . '</h1>';
        $html .= '<table cellpadding="4" border="0"><tr>'
            . '<td width="30%" style="background-color:' . ($overall_colors[$run['overall']] ?? '#868e96') . ';color:#ffffff;text-align:center;">'
            . '<span style="font-size:28pt;font-weight:bold;">' . (int) $run['score'] . '</span><br/>'
            . $e(Score::getOverallLabel($run['overall'])) . '</td>'
            . '<td width="70%">'
            . '<b>' . $e(__('Data da análise', 'healthcheck')) . ':</b> ' . $e($run['date_creation']) . '<br/>'
            . '<b>' . $e(__('Tipo', 'healthcheck')) . ':</b> ' . $e(ReportView::modeLabel($run['mode'])) . ' / ' . $e(ReportView::triggerLabel($run['trigger_type'])) . '<br/>'
            . '<b>' . $e(__('Aprovadas', 'healthcheck')) . ':</b> ' . (int) $run['nb_ok']
            . ' &nbsp; <b>' . $e(__('Alertas', 'healthcheck')) . ':</b> ' . (int) $run['nb_warning']
            . ' &nbsp; <b>' . $e(__('Críticos', 'healthcheck')) . ':</b> ' . (int) $run['nb_critical']
            . ' &nbsp; <b>' . $e(__('Não verificadas', 'healthcheck')) . ':</b> ' . ((int) $run['nb_skipped'] + (int) $run['nb_error']) . '<br/>'
            . '</td></tr></table>';

        $html .= '<h2>' . $e(__('Identificação do ambiente', 'healthcheck')) . '</h2><table cellpadding="3" border="1">';
        $env_labels = [
            'url_base'       => __('URL', 'healthcheck'),
            'hostname'       => __('Servidor', 'healthcheck'),
            'glpi_version'   => __('Versão do GLPI', 'healthcheck'),
            'php_version'    => __('Versão do PHP', 'healthcheck'),
            'php_sapi'       => __('Interface PHP', 'healthcheck'),
            'db_version'     => __('Banco de dados', 'healthcheck'),
            'os'             => __('Sistema operacional', 'healthcheck'),
            'plugin_version' => __('Versão do Health Check', 'healthcheck'),
        ];
        foreach ($env_labels as $key => $label) {
            $html .= '<tr><td width="30%"><b>' . $e($label) . '</b></td><td width="70%">' . $e($env[$key] ?? '-') . '</td></tr>';
        }
        $html .= '</table>';

        // Summary table
        $html .= '<h2>' . $e(__('Resumo das verificações', 'healthcheck')) . '</h2>';
        $html .= '<table cellpadding="3" border="1"><tr style="background-color:#e9ecef;font-weight:bold;">'
            . '<td width="22%">' . $e(__('Categoria', 'healthcheck')) . '</td>'
            . '<td width="38%">' . $e(__('Verificação', 'healthcheck')) . '</td>'
            . '<td width="20%">' . $e(__('Status', 'healthcheck')) . '</td>'
            . '<td width="20%">' . $e(__('Criticidade', 'healthcheck')) . '</td></tr>';
        foreach ($this->results as $result) {
            $html .= '<tr>'
                . '<td>' . $e(Category::getLabel($result['category'])) . '</td>'
                . '<td>' . $e($result['title']) . '</td>'
                . '<td style="color:' . ($colors[$result['status']] ?? '#000') . ';font-weight:bold;">' . $e(Status::getLabel($result['status'])) . '</td>'
                . '<td>' . $e(Severity::getLabel((int) $result['severity'])) . '</td></tr>';
        }
        $html .= '</table>';

        // Details of problems
        $problems = array_filter($this->results, static fn ($r) => Status::isProblem($r['status']) || $r['status'] === Status::ERROR);
        if (count($problems) > 0) {
            $html .= '<h2>' . $e(__('Problemas identificados e recomendações', 'healthcheck')) . '</h2>';
            foreach ($problems as $result) {
                $details = $result['details'];
                $html .= '<table cellpadding="4" border="1" nobr="true"><tr><td style="background-color:' . ($colors[$result['status']] ?? '#000') . ';color:#ffffff;">'
                    . '<b>' . $e($result['title']) . '</b> — ' . $e(Status::getLabel($result['status']))
                    . ' / ' . $e(__('Criticidade', 'healthcheck')) . ': ' . $e(Severity::getLabel((int) $result['severity'])) . '</td></tr><tr><td>';
                $html .= '<b>' . $e(__('Descrição', 'healthcheck')) . ':</b> ' . $e($result['message']) . '<br/>';
                foreach (['impact' => __('Impacto operacional', 'healthcheck'), 'cause' => __('Possível causa', 'healthcheck'), 'recommendation' => __('Recomendação', 'healthcheck')] as $field => $label) {
                    if (!empty($details[$field])) {
                        $html .= '<b>' . $e($label) . ':</b> ' . $e($details[$field]) . '<br/>';
                    }
                }
                if (!empty($details['steps'])) {
                    $html .= '<b>' . $e(__('Passo a passo', 'healthcheck')) . ':</b><ol>';
                    foreach ($details['steps'] as $step) {
                        $html .= '<li>' . $e($step) . '</li>';
                    }
                    $html .= '</ol>';
                }
                if (!empty($details['doc_url'])) {
                    $html .= '<b>' . $e(__('Documentação', 'healthcheck')) . ':</b> <a href="' . $e($details['doc_url']) . '">' . $e($details['doc_url']) . '</a><br/>';
                }
                if (!empty($details['items']) && !empty($details['columns'])) {
                    $html .= '<br/><table cellpadding="2" border="1" style="font-size:7pt;"><tr style="background-color:#f1f3f5;">';
                    foreach ($details['columns'] as $label) {
                        $html .= '<td><b>' . $e($label) . '</b></td>';
                    }
                    $html .= '</tr>';
                    foreach (array_slice($details['items'], 0, 30) as $item) {
                        $html .= '<tr>';
                        foreach (array_keys($details['columns']) as $key) {
                            $html .= '<td>' . nl2br($e($item[$key] ?? '')) . '</td>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</table>';
                    if (($details['items_total'] ?? 0) > 30) {
                        $html .= '<i>' . $e(sprintf(__('%d de %d itens exibidos.', 'healthcheck'), 30, $details['items_total'])) . '</i>';
                    }
                }
                $html .= '</td></tr></table><br/>';
            }
        }

        $html .= '<p style="font-size:7pt;color:#666666;">' . $e(__('Relatório gerado localmente pelo plugin Health Check. O plugin é exclusivamente consultivo: nenhuma correção foi aplicada automaticamente.', 'healthcheck')) . '</p>';

        return $html;
    }
}
