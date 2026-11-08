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

class PhpSettingsCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'performance.php_settings';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Configurações de desempenho do PHP', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $items    = [];
        $findings = [];
        $add = function (string $setting, string $value, string $advice, int $severity) use (&$items, &$findings) {
            $findings[] = [Status::WARNING, $severity];
            $items[] = ['setting' => $setting, 'value' => $value, 'advice' => $advice];
        };

        $opcache_loaded  = extension_loaded('Zend OPcache');
        $opcache_enabled = $opcache_loaded && filter_var(ini_get($this->isCli() ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOLEAN);

        if (!$this->isCli()) {
            if (!$opcache_enabled) {
                $add('opcache.enable', $opcache_loaded ? 'Off' : __('extensão ausente', 'healthcheck'), __('Sem OPcache o PHP recompila o código a cada requisição: o GLPI fica várias vezes mais lento.', 'healthcheck'), Severity::MEDIUM);
            } elseif (function_exists('opcache_get_status')) {
                $status = @opcache_get_status(false);
                if (is_array($status)) {
                    $mem = $status['memory_usage'] ?? [];
                    $used = (float) ($mem['used_memory'] ?? 0);
                    $free = (float) ($mem['free_memory'] ?? 0);
                    $wasted = (float) ($mem['wasted_memory'] ?? 0);
                    $total = $used + $free + $wasted;
                    if (!empty($status['cache_full']) || ($total > 0 && $free / $total < 0.05)) {
                        $add('opcache.memory_consumption', Format::bytes($total), __('Memória do OPcache esgotada: scripts deixam de ser armazenados. Aumente para 256 MB ou mais.', 'healthcheck'), Severity::LOW);
                    }
                    $max_files = (int) ini_get('opcache.max_accelerated_files');
                    $cached    = (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0);
                    if ($max_files > 0 && $cached >= $max_files * 0.9) {
                        $add('opcache.max_accelerated_files', (string) $max_files, __('Limite de arquivos em cache quase atingido. Recomendado 20000 ou mais.', 'healthcheck'), Severity::LOW);
                    }
                }
            }

            $max_exec = (int) ini_get('max_execution_time');
            if ($max_exec > 0 && $max_exec < 30) {
                $add('max_execution_time', $max_exec . ' s', __('Valor baixo interrompe exportações, relatórios e ações em massa. Recomendado 30 s ou mais na web.', 'healthcheck'), Severity::LOW);
            }
        }

        // Upload limits vs GLPI document size limit
        $doc_max   = (int) $this->cfg('document_max_size', 2) * 1024 * 1024;
        $upload    = Format::iniBytes((string) ini_get('upload_max_filesize'));
        $post      = Format::iniBytes((string) ini_get('post_max_size'));
        if ($upload > 0 && $upload < $doc_max) {
            $add('upload_max_filesize', Format::bytes($upload), sprintf(__('Menor que o tamanho máximo de documento configurado no GLPI (%s): uploads maiores falharão.', 'healthcheck'), Format::bytes($doc_max)), Severity::LOW);
        }
        if ($post > 0 && $upload > 0 && $post < $upload) {
            $add('post_max_size', Format::bytes($post), __('Menor que upload_max_filesize: formulários com anexos podem perder dados.', 'healthcheck'), Severity::LOW);
        }

        $facts = [
            __('Contexto', 'healthcheck')              => $this->isCli() ? 'CLI' : PHP_SAPI,
            'OPcache'                                  => $opcache_enabled,
            'max_execution_time'                       => ini_get('max_execution_time') . ' s',
            'upload_max_filesize'                      => ini_get('upload_max_filesize'),
            'post_max_size'                            => ini_get('post_max_size'),
            __('Tamanho máximo de documento (GLPI)', 'healthcheck') => Format::bytes($doc_max),
            'realpath_cache_size'                      => ini_get('realpath_cache_size'),
        ];

        if (count($items) === 0) {
            $message = __('As configurações de desempenho do PHP estão adequadas.', 'healthcheck');
            if ($this->isCli()) {
                $message .= ' ' . __('(OPcache e limites web só são avaliados em execução pelo navegador.)', 'healthcheck');
            }
            return CheckResult::ok($message)->facts($facts);
        }

        return CheckResult::worstOf($findings, '', sprintf(__('%d configuração(ões) do PHP afetam o desempenho.', 'healthcheck'), count($items)))
            ->impact(__('Páginas lentas, uploads e exportações que falham.', 'healthcheck'))
            ->cause(__('php.ini com valores padrão da distribuição.', 'healthcheck'))
            ->recommendation(__('Ajuste os parâmetros no php.ini usado pelo servidor web e reinicie o serviço.', 'healthcheck'))
            ->doc(self::DOC_PREREQUISITES)
            ->facts($facts)
            ->items($items, [
                'setting' => __('Parâmetro', 'healthcheck'),
                'value'   => __('Valor atual', 'healthcheck'),
                'advice'  => __('Recomendação', 'healthcheck'),
            ]);
    }
}
