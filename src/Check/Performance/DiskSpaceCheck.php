<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Performance;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\Format;
use GlpiPlugin\Healthcheck\Settings;

class DiskSpaceCheck extends AbstractCheck
{
    /** Absolute free space considered critical whatever the percentage. */
    private const CRITICAL_BYTES = 1073741824; // 1 GB

    public function getKey(): string
    {
        return 'performance.disk_space';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Espaço disponível em disco', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $targets = [
            __('Aplicação', 'healthcheck')                => GLPI_ROOT,
            __('Dados e documentos', 'healthcheck')       => GLPI_DOC_DIR,
            __('Logs', 'healthcheck')                     => GLPI_LOG_DIR,
            __('Temporários', 'healthcheck')              => GLPI_TMP_DIR,
            __('Temporário do sistema', 'healthcheck')    => sys_get_temp_dir(),
        ];
        if (ini_get('session.save_handler') === 'files') {
            $targets[__('Sessões', 'healthcheck')] = GLPI_SESSION_DIR;
        }

        $warn_pct = Settings::getInt('disk_warning_percent');
        $crit_pct = Settings::getInt('disk_critical_percent');

        $volumes  = [];
        $skipped  = [];
        foreach ($targets as $label => $path) {
            if (!is_dir($path)) {
                continue;
            }
            $free  = @disk_free_space($path);
            $total = @disk_total_space($path);
            if ($free === false || $total === false || $total <= 0) {
                $skipped[] = $label;
                continue;
            }
            // Same volume => same free/total pair
            $volume_key = $total . '|' . $free;
            if (!isset($volumes[$volume_key])) {
                $volumes[$volume_key] = ['labels' => [], 'path' => $path, 'free' => $free, 'total' => $total];
            }
            $volumes[$volume_key]['labels'][] = $label;
        }

        if (count($volumes) === 0) {
            return CheckResult::skipped(__('Não foi possível obter o espaço em disco (funções disk_free_space/disk_total_space indisponíveis ou bloqueadas por open_basedir).', 'healthcheck'));
        }

        $items    = [];
        $findings = [];
        foreach ($volumes as $volume) {
            $pct_free = Format::percent($volume['free'], $volume['total']);
            $status = Status::OK;
            if ($pct_free < $crit_pct || $volume['free'] < self::CRITICAL_BYTES) {
                $status = Status::CRITICAL;
                $findings[] = [Status::CRITICAL, Severity::CRITICAL];
            } elseif ($pct_free < $warn_pct) {
                $status = Status::WARNING;
                $findings[] = [Status::WARNING, Severity::HIGH];
            }
            $items[] = [
                'usage'  => implode(', ', $volume['labels']),
                'path'   => $volume['path'],
                'total'  => Format::bytes($volume['total']),
                'free'   => Format::bytes($volume['free']),
                'pct'    => Format::number($pct_free, 1) . '%',
                'status' => Status::getLabel($status),
            ];
        }

        $columns = [
            'usage'  => __('Uso', 'healthcheck'),
            'path'   => __('Caminho', 'healthcheck'),
            'total'  => __('Tamanho', 'healthcheck'),
            'free'   => __('Livre', 'healthcheck'),
            'pct'    => __('% livre', 'healthcheck'),
            'status' => __('Situação', 'healthcheck'),
        ];
        $facts = [
            __('Limite de alerta', 'healthcheck')  => $warn_pct . '%',
            __('Limite crítico', 'healthcheck')    => sprintf('%d%% / %s', $crit_pct, Format::bytes(self::CRITICAL_BYTES)),
        ];
        if (count($skipped) > 0) {
            $facts[__('Não verificados', 'healthcheck')] = implode(', ', $skipped);
        }

        if (count($findings) === 0) {
            return CheckResult::ok(__('Há espaço em disco suficiente em todos os volumes usados pelo GLPI.', 'healthcheck'))
                ->facts($facts)
                ->items($items, $columns);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            __('Espaço em disco baixo em volume(s) utilizado(s) pelo GLPI.', 'healthcheck')
        )
            ->impact(__('Com o disco cheio o GLPI não grava documentos, sessões, cache nem logs; usuários são desconectados e o banco pode parar se estiver no mesmo volume.', 'healthcheck'))
            ->cause(__('Crescimento de documentos anexados, logs sem rotação, arquivos temporários ou backups acumulados.', 'healthcheck'))
            ->recommendation(__('Libere espaço ou amplie o volume; ative as rotinas de limpeza do GLPI.', 'healthcheck'))
            ->steps([
                __('Identifique os maiores diretórios (ex.: "du -sh files/*").', 'healthcheck'),
                __('Verifique se as ações automáticas "temp", "circularlogs" e "session" estão ativas.', 'healthcheck'),
                __('Remova backups e dumps antigos de files/_dumps.', 'healthcheck'),
                __('Amplie o volume ou mova files/ para um volume maior.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->facts($facts)
            ->items($items, $columns);
    }
}
