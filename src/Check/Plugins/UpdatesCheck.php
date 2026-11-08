<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Plugins;

use Glpi\Marketplace\Controller;
use GLPINetwork;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Settings;
use Plugin;

/**
 * Available plugin updates, from GLPI's marketplace catalog.
 * Optional external lookup: requires "allow external checks" and a GLPI Network registration key.
 */
class UpdatesCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'plugins.updates';
    }

    public function getCategory(): string
    {
        return Category::PLUGINS;
    }

    public function getTitle(): string
    {
        return __('Atualizações de plugins disponíveis', 'healthcheck');
    }

    public function isLight(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        if (!Settings::allowExternal()) {
            return CheckResult::skipped(__('Consulta externa desativada nas configurações do plugin. Verifique atualizações em Configurar > Plugins > Marketplace.', 'healthcheck'));
        }
        if (!GLPINetwork::isRegistered()) {
            return CheckResult::skipped(__('O catálogo do Marketplace exige uma chave de registro GLPI Network (gratuita) configurada em Configurar > Geral > GLPI Network.', 'healthcheck'));
        }

        $api = Controller::getAPI();
        $items = [];
        $checked = 0;
        foreach ($this->db()->request(['FROM' => Plugin::getTable(), 'ORDER' => 'name']) as $plugin) {
            if (in_array((int) $plugin['state'], [Plugin::TOBECLEANED, Plugin::REPLACED], true)) {
                continue;
            }
            $remote = $api->getPlugin($plugin['directory']);
            if (count($remote) === 0) {
                continue; // not distributed through the marketplace
            }
            $checked++;
            $remote_version = (string) ($remote['version'] ?? '');
            if ($remote_version !== '' && version_compare($remote_version, (string) $plugin['version'], '>')) {
                $items[] = [
                    'name'      => $plugin['name'],
                    'installed' => $plugin['version'],
                    'available' => $remote_version,
                    'state'     => Plugin::getState((int) $plugin['state']),
                ];
            }
        }

        $facts = [__('Plugins encontrados no Marketplace', 'healthcheck') => $checked];

        if ($checked === 0) {
            return CheckResult::skipped(__('Nenhum plugin instalado foi encontrado no catálogo do Marketplace (ou o catálogo está inacessível).', 'healthcheck'))
                ->facts($facts);
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('Todos os plugins do Marketplace estão na versão mais recente.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::warning(
            sprintf(__('%d plugin(s) com atualização disponível.', 'healthcheck'), count($items)),
            Severity::LOW
        )
            ->impact(__('Versões novas de plugins trazem correções de segurança e compatibilidade com versões futuras do GLPI.', 'healthcheck'))
            ->recommendation(__('Leia o changelog de cada plugin e atualize em janela de manutenção, após backup.', 'healthcheck'))
            ->steps([
                __('Acesse Configurar > Plugins e use o botão de atualização do Marketplace.', 'healthcheck'),
                __('Depois de atualizar os arquivos, execute a atualização do plugin e reative-o.', 'healthcheck'),
            ])
            ->doc(self::DOC_PLUGINS)
            ->facts($facts)
            ->items($items, [
                'name'      => __('Plugin', 'healthcheck'),
                'installed' => __('Instalada', 'healthcheck'),
                'available' => __('Disponível', 'healthcheck'),
                'state'     => __('Status', 'healthcheck'),
            ]);
    }
}
