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
use Plugin;

/**
 * Inventory of installed plugins and abnormal states.
 */
class StateCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'plugins.state';
    }

    public function getCategory(): string
    {
        return Category::PLUGINS;
    }

    public function getTitle(): string
    {
        return __('Plugins instalados e status', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $plugins = iterator_to_array($this->db()->request([
            'FROM'  => Plugin::getTable(),
            'ORDER' => ['name', 'directory'],
        ]), false);

        $items    = [];
        $findings = [];
        $counts   = ['active' => 0, 'inactive' => 0, 'problem' => 0];

        foreach ($plugins as $plugin) {
            $state    = (int) $plugin['state'];
            $dir      = Plugin::getPhpDir($plugin['directory']);
            $problem  = '-';

            switch ($state) {
                case Plugin::ACTIVATED:
                    $counts['active']++;
                    break;
                case Plugin::NOTUPDATED:
                    $problem = __('Arquivos atualizados, aguardando atualização (plugin desativado até concluir)', 'healthcheck');
                    $findings[] = [Status::WARNING, Severity::HIGH];
                    $counts['problem']++;
                    break;
                case Plugin::TOBECLEANED:
                    $problem = __('Diretório do plugin não encontrado; registro a ser limpo', 'healthcheck');
                    $findings[] = [Status::WARNING, Severity::MEDIUM];
                    $counts['problem']++;
                    break;
                case Plugin::TOBECONFIGURED:
                    $problem = __('Instalado mas não configurado', 'healthcheck');
                    $findings[] = [Status::WARNING, Severity::MEDIUM];
                    $counts['problem']++;
                    break;
                case Plugin::REPLACED:
                    $problem = __('Substituído por funcionalidade nativa ou outro plugin; pode ser desinstalado', 'healthcheck');
                    $findings[] = [Status::WARNING, Severity::LOW];
                    $counts['problem']++;
                    break;
                case Plugin::NOTACTIVATED:
                    $problem = __('Instalado e desativado (suspenso)', 'healthcheck');
                    $counts['inactive']++;
                    break;
                default:
                    $counts['inactive']++;
            }

            if ($state !== Plugin::TOBECLEANED && $dir === false) {
                $problem = __('Diretório do plugin não encontrado', 'healthcheck');
                $findings[] = [Status::WARNING, Severity::MEDIUM];
            }

            $items[] = [
                'name'      => $plugin['name'],
                'directory' => $plugin['directory'],
                'version'   => $plugin['version'],
                'state'     => Plugin::getState($state),
                'location'  => $dir !== false ? str_replace(str_replace('\\', '/', GLPI_ROOT), '', $dir) : '-',
                'problem'   => $problem,
            ];
        }

        $columns = [
            'name'      => __('Plugin', 'healthcheck'),
            'directory' => __('Chave', 'healthcheck'),
            'version'   => __('Versão', 'healthcheck'),
            'state'     => __('Status', 'healthcheck'),
            'location'  => __('Local', 'healthcheck'),
            'problem'   => __('Observação', 'healthcheck'),
        ];
        $facts = [
            __('Plugins registrados', 'healthcheck')        => count($plugins),
            __('Ativos', 'healthcheck')                     => $counts['active'],
            __('Inativos / não instalados', 'healthcheck')  => $counts['inactive'],
            __('Em estado anormal', 'healthcheck')          => $counts['problem'],
        ];

        if (count($findings) === 0) {
            return CheckResult::ok(sprintf(__('%1$d plugin(s) registrado(s), %2$d ativo(s), nenhum em estado anormal.', 'healthcheck'), count($plugins), $counts['active']))
                ->facts($facts)
                ->items($items, $columns);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d plugin(s) em estado anormal.', 'healthcheck'), count($findings))
        )
            ->impact(__('Plugins pendentes de atualização ficam desativados (funcionalidades indisponíveis) e registros órfãos podem atrapalhar atualizações do GLPI.', 'healthcheck'))
            ->cause(__('Atualização dos arquivos sem executar a atualização do plugin, ou remoção do diretório sem desinstalar.', 'healthcheck'))
            ->recommendation(__('Conclua a atualização ou desinstale corretamente os plugins listados.', 'healthcheck'))
            ->steps([
                __('Acesse Configurar > Plugins.', 'healthcheck'),
                __('Para "A atualizar", clique em atualizar e depois ative o plugin.', 'healthcheck'),
                __('Para "Erro / a limpar", use a ação de limpeza.', 'healthcheck'),
                __('Por linha de comando: "php bin/console plugin:install --force NOME" e "php bin/console plugin:activate NOME".', 'healthcheck'),
            ])
            ->doc(self::DOC_PLUGINS)
            ->facts($facts)
            ->items($items, $columns);
    }
}
