<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Plugins;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\ReleaseInfo;
use Plugin;

/**
 * Compares requirements declared by each plugin with the running GLPI and PHP versions,
 * and, when a newer GLPI branch is known, with that branch.
 */
class CompatibilityCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'plugins.compatibility';
    }

    public function getCategory(): string
    {
        return Category::PLUGINS;
    }

    public function getTitle(): string
    {
        return __('Compatibilidade dos plugins', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $glpi_version = ReleaseInfo::currentVersion();
        $releases     = ReleaseInfo::get();
        $next         = null;
        if ($releases['latest'] !== null && ReleaseInfo::branchOf($releases['latest']) !== ReleaseInfo::branchOf(GLPI_VERSION)
            && version_compare($releases['latest'], $glpi_version, '>')) {
            $next = $releases['latest'];
        }

        $plugin_inst = new Plugin();
        $items       = [];
        $findings    = [];
        $not_ready   = 0;
        $analyzed    = 0;

        foreach ($this->db()->request(['FROM' => Plugin::getTable(), 'ORDER' => 'name']) as $plugin) {
            $key   = $plugin['directory'];
            $state = (int) $plugin['state'];
            if (in_array($state, [Plugin::TOBECLEANED, Plugin::REPLACED], true) || Plugin::getPhpDir($key) === false) {
                continue;
            }
            $active = $state === Plugin::ACTIVATED;

            ob_start(); // some old plugins echo while loading their setup file
            try {
                $info = $plugin_inst->getInformationsFromDirectory($key, false);
            } finally {
                ob_end_clean();
            }
            if (!is_array($info) || count($info) === 0) {
                $findings[] = [Status::WARNING, Severity::MEDIUM];
                $items[] = $this->row($plugin, '-', '-', __('Não foi possível ler as informações do plugin (setup.php)', 'healthcheck'), '-');
                continue;
            }
            $analyzed++;

            $glpi_req = $info['requirements']['glpi'] ?? [];
            $min = $glpi_req['min'] ?? ($info['minGlpiVersion'] ?? null);
            $max = $glpi_req['max'] ?? null;
            $php_req = $info['requirements']['php'] ?? [];

            $problems = [];
            if (!$this->isInRange($glpi_version, $min, $max)) {
                $findings[] = $active ? [Status::CRITICAL, Severity::HIGH] : [Status::WARNING, Severity::LOW];
                $problems[] = sprintf(__('Incompatível com o GLPI %s', 'healthcheck'), GLPI_VERSION);
            }
            if (!$this->isInRange(PHP_VERSION, $php_req['min'] ?? null, $php_req['max'] ?? null)) {
                $findings[] = $active ? [Status::CRITICAL, Severity::HIGH] : [Status::WARNING, Severity::LOW];
                $problems[] = sprintf(__('Incompatível com o PHP %s', 'healthcheck'), PHP_VERSION);
            }
            if ($max === null) {
                $problems[] = __('Não declara versão máxima do GLPI suportada', 'healthcheck');
            }
            if (isset($info['version']) && $plugin['version'] !== $info['version'] && $state !== Plugin::NOTUPDATED) {
                $findings[] = [Status::WARNING, Severity::MEDIUM];
                $problems[] = sprintf(
                    __('Versão dos arquivos (%1$s) difere da versão instalada no banco (%2$s)', 'healthcheck'),
                    $info['version'],
                    $plugin['version']
                );
            }

            $next_status = '-';
            if ($next !== null) {
                if ($max === null) {
                    $next_status = __('Não declarado', 'healthcheck');
                } elseif ($this->isInRange($next, $min, $max)) {
                    $next_status = __('Sim', 'healthcheck');
                } else {
                    $next_status = __('Não', 'healthcheck');
                    if ($active) {
                        $not_ready++;
                        $findings[] = [Status::INFO, Severity::NONE];
                    }
                }
            }

            $range = sprintf('%s – %s', $min ?? '?', $max ?? '?');
            $items[] = $this->row($plugin, $info['version'] ?? $plugin['version'], $range, count($problems) > 0 ? implode('; ', $problems) : '-', $next_status);
        }

        $columns = [
            'name'    => __('Plugin', 'healthcheck'),
            'version' => __('Versão', 'healthcheck'),
            'state'   => __('Status', 'healthcheck'),
            'range'   => __('GLPI suportado (mín – máx exclusivo)', 'healthcheck'),
            'next'    => $next !== null ? sprintf(__('Compatível com %s', 'healthcheck'), $next) : __('Próxima versão', 'healthcheck'),
            'problem' => __('Observação', 'healthcheck'),
        ];
        $facts = [
            __('Plugins analisados', 'healthcheck')     => $analyzed,
            __('Versão do GLPI', 'healthcheck')         => GLPI_VERSION,
            __('Próxima versão principal conhecida', 'healthcheck') => $next ?? __('Desconhecida', 'healthcheck'),
        ];
        if ($next !== null) {
            $facts[__('Plugins ativos que bloqueiam a atualização', 'healthcheck')] = $not_ready;
        }

        if (count($items) === 0) {
            return CheckResult::info(__('Nenhum plugin instalado além dos registros sem diretório.', 'healthcheck'))
                ->facts($facts);
        }

        $result = CheckResult::worstOf(
            $findings,
            $not_ready > 0
                ? sprintf(__('Plugins compatíveis com a versão atual. %1$d plugin(s) ativo(s) ainda não declara(m) compatibilidade com o GLPI %2$s.', 'healthcheck'), $not_ready, $next)
                : __('Todos os plugins são compatíveis com as versões atuais do GLPI e do PHP.', 'healthcheck'),
            __('Há plugins incompatíveis com a versão instalada do GLPI ou do PHP, ou com versão inconsistente.', 'healthcheck')
        )
            ->facts($facts)
            ->items($items, $columns);

        if ($result->getStatus() !== Status::OK) {
            $result
                ->impact(__('Plugins incompatíveis podem causar erros fatais, telas em branco e impedir a atualização do GLPI.', 'healthcheck'))
                ->cause(__('Atualização do GLPI ou do PHP sem atualizar os plugins, ou plugins abandonados pelos autores.', 'healthcheck'))
                ->recommendation(__('Atualize os plugins para versões compatíveis antes de atualizar o GLPI; desative os que não tiverem versão compatível.', 'healthcheck'))
                ->steps([
                    __('Consulte a página/repositório de cada plugin e verifique a matriz de compatibilidade.', 'healthcheck'),
                    __('Atualize pelo Marketplace ou substituindo o diretório do plugin, e execute a atualização em Configurar > Plugins.', 'healthcheck'),
                    __('Antes de atualizar o GLPI, desative os plugins sem versão compatível com a nova versão.', 'healthcheck'),
                ])
                ->doc(self::DOC_PLUGINS);
        }
        return $result;
    }

    private function row(array $plugin, string $version, string $range, string $problem, string $next): array
    {
        return [
            'name'    => $plugin['name'],
            'version' => $version,
            'state'   => Plugin::getState((int) $plugin['state']),
            'range'   => $range,
            'next'    => $next,
            'problem' => $problem,
        ];
    }

    private function isInRange(string $version, ?string $min, ?string $max): bool
    {
        if ($min !== null && $min !== '' && version_compare($version, $min, '<')) {
            return false;
        }
        if ($max !== null && $max !== '' && version_compare($version, $max, '>=')) {
            return false;
        }
        return true;
    }
}
