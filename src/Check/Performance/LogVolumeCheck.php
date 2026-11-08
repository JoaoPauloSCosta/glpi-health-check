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

class LogVolumeCheck extends AbstractCheck
{
    private const WARNING_BYTES = 524288000;   // 500 MB
    private const HIGH_BYTES    = 2147483648;  // 2 GB

    public function getKey(): string
    {
        return 'performance.logs';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Volume de logs', 'healthcheck');
    }

    public function run(): CheckResult
    {
        if (!is_dir(GLPI_LOG_DIR) || !is_readable(GLPI_LOG_DIR)) {
            return CheckResult::skipped(__('O diretório de logs não existe ou não pode ser lido.', 'healthcheck'));
        }

        $files = [];
        $total = 0;
        foreach (new \DirectoryIterator(GLPI_LOG_DIR) as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $size = $file->getSize();
            $total += $size;
            $files[] = [
                'file'  => $file->getFilename(),
                'size'  => $size,
                'mtime' => $file->getMTime(),
            ];
        }
        usort($files, static fn ($a, $b) => $b['size'] <=> $a['size']);

        $findings = [];
        $notes    = [];
        if ($total >= self::HIGH_BYTES) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
        } elseif ($total >= self::WARNING_BYTES) {
            $findings[] = [Status::WARNING, Severity::LOW];
        }

        // Recent application errors
        $recent_errors = [];
        foreach (['php-errors.log' => __('erro(s) de PHP', 'healthcheck'), 'sql-errors.log' => __('erro(s) de SQL', 'healthcheck')] as $name => $label) {
            $path = GLPI_LOG_DIR . '/' . $name;
            if (!is_file($path) || !is_readable($path) || ($this->now() - (int) filemtime($path)) > DAY_TIMESTAMP) {
                continue;
            }
            $count = $this->countRecentEntries($path, $this->now() - DAY_TIMESTAMP);
            $recent_errors[$name] = $count;
            if ($count > 0) {
                $findings[] = [Status::WARNING, $count > 100 ? Severity::MEDIUM : Severity::LOW];
                $notes[] = sprintf(__('%1$s registrou %2$d %3$s nas últimas 24 horas.', 'healthcheck'), $name, $count, $label);
            }
        }

        $items = array_map(fn ($file) => [
            'file'     => $file['file'],
            'size'     => Format::bytes($file['size']),
            'modified' => date('Y-m-d H:i:s', $file['mtime']),
        ], array_slice($files, 0, 10));

        // History table in database
        $history_rows = $this->approximateRows('glpi_logs');
        $purge_enabled = false;
        foreach ($this->db()->request(['FROM' => 'glpi_configs', 'WHERE' => ['context' => 'core', 'name' => ['LIKE', 'purge\_%']]]) as $row) {
            if ((int) $row['value'] !== 0) {
                $purge_enabled = true;
                break;
            }
        }
        if ($history_rows > 5000000 && !$purge_enabled) {
            $findings[] = [Status::WARNING, Severity::LOW];
            $notes[] = sprintf(__('O histórico de alterações (glpi_logs) tem cerca de %s registros e nenhuma purga automática está configurada.', 'healthcheck'), Format::number($history_rows));
        }

        $facts = [
            __('Tamanho total de files/_log', 'healthcheck')     => Format::bytes($total),
            __('Erros de PHP nas últimas 24h', 'healthcheck')    => $recent_errors['php-errors.log'] ?? 0,
            __('Erros de SQL nas últimas 24h', 'healthcheck')    => $recent_errors['sql-errors.log'] ?? 0,
            __('Arquivos de log', 'healthcheck')                 => count($files),
            __('Registros em glpi_logs (aprox.)', 'healthcheck') => Format::number($history_rows),
            __('Purga do histórico configurada', 'healthcheck')  => $purge_enabled,
        ];
        $columns = [
            'file'     => __('Arquivo', 'healthcheck'),
            'size'     => __('Tamanho', 'healthcheck'),
            'modified' => __('Modificado em', 'healthcheck'),
        ];

        if (count($findings) === 0) {
            return CheckResult::ok(sprintf(__('Volume de logs sob controle (%s).', 'healthcheck'), Format::bytes($total)))
                ->facts($facts)
                ->items($items, $columns);
        }

        $message = $total >= self::WARNING_BYTES
            ? sprintf(__('Os arquivos de log ocupam %s.', 'healthcheck'), Format::bytes($total))
            : __('Os logs indicam pontos de atenção.', 'healthcheck');
        if (count($notes) > 0) {
            $message .= ' ' . implode(' ', $notes);
        }

        return CheckResult::worstOf($findings, '', $message)
            ->impact(__('Logs volumosos consomem disco e dificultam a análise de problemas; erros recentes podem indicar falhas em funcionalidades.', 'healthcheck'))
            ->cause(__('Rotação de logs desativada (ação "circularlogs"), modo debug, erros repetitivos de plugins ou ausência de purga do histórico.', 'healthcheck'))
            ->recommendation(__('Analise os erros recentes, ative a rotação de logs e configure a purga do histórico.', 'healthcheck'))
            ->steps([
                __('Leia as últimas linhas de php-errors.log e sql-errors.log para identificar a origem dos erros.', 'healthcheck'),
                __('Ative a ação automática "circularlogs" (rotação dos arquivos de log).', 'healthcheck'),
                __('Configure a purga do histórico em Configurar > Geral > Logs (ação automática "PurgeLogs").', 'healthcheck'),
                __('Compacte ou remova arquivos de log antigos já analisados.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, $columns);
    }

    /**
     * Count log entries newer than $since, reading only the end of the file.
     * Entries start with a date, either "[Y-m-d H:i:s] channel.LEVEL: ..." (php-errors)
     * or "Y-m-d H:i:s [user@host]" (other GLPI logs).
     * The "Test logger" messages written by GLPI's own requirements check are ignored.
     */
    private function countRecentEntries(string $file, int $since): int
    {
        $size = (int) filesize($file);
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return 0;
        }
        if ($size > 262144) {
            fseek($handle, -262144, SEEK_END);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        $count = 0;
        $current_ts = null;
        $current_text = '';
        $flush = static function () use (&$count, &$current_ts, &$current_text, $since) {
            if ($current_ts !== null && $current_ts >= $since && stripos($current_text, 'Test logger') === false) {
                $count++;
            }
        };
        foreach (preg_split('/\R/', $content) as $line) {
            if (preg_match('/^\[?(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches) === 1) {
                $flush();
                $current_ts   = strtotime($matches[1]) ?: null;
                $current_text = $line;
            } else {
                $current_text .= "\n" . $line;
            }
        }
        $flush();
        return $count;
    }

    private function approximateRows(string $table): int
    {
        $row = $this->db()->request([
            'SELECT' => ['TABLE_ROWS'],
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => ['TABLE_SCHEMA' => $this->db()->dbdefault, 'TABLE_NAME' => $table],
        ])->current();
        return (int) ($row['TABLE_ROWS'] ?? 0);
    }
}
