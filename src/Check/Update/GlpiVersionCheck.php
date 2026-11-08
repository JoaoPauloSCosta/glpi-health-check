<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Update;

use Glpi\Toolbox\VersionParser;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\ReleaseInfo;

class GlpiVersionCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'update.glpi_version';
    }

    public function getCategory(): string
    {
        return Category::UPDATE;
    }

    public function getTitle(): string
    {
        return __('Versão do GLPI', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Compara a versão instalada com as versões estáveis conhecidas.', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $installed = ReleaseInfo::currentVersion();
        $branch    = ReleaseInfo::branchOf(GLPI_VERSION);
        $releases  = ReleaseInfo::get();

        $source_labels = [
            'external' => __('Consulta externa (releases públicas do GLPI no GitHub)', 'healthcheck'),
            'glpi'     => __('Ação automática "checkupdate" do GLPI', 'healthcheck'),
            'none'     => __('Indisponível', 'healthcheck'),
        ];

        $facts = [
            __('Versão instalada', 'healthcheck')            => GLPI_VERSION,
            __('Ramo', 'healthcheck')                        => $branch,
            __('Última versão do mesmo ramo', 'healthcheck') => $releases['latest_same_branch'],
            __('Última versão estável', 'healthcheck')       => $releases['latest'],
            __('Fonte da informação', 'healthcheck')         => $source_labels[$releases['source']] ?? $releases['source'],
        ];
        if ($releases['fetched_at'] !== null) {
            $facts[__('Consultado em', 'healthcheck')] = $releases['fetched_at'];
        }

        if (!VersionParser::isStableRelease(GLPI_VERSION)) {
            return CheckResult::warning(
                sprintf(__('A versão instalada (%s) não é uma versão estável.', 'healthcheck'), GLPI_VERSION),
                Severity::HIGH
            )
                ->impact(__('Versões de desenvolvimento, alpha, beta ou RC podem conter falhas e não têm caminho de atualização garantido.', 'healthcheck'))
                ->cause(__('Instalação de um pacote de pré-lançamento ou do código-fonte de desenvolvimento.', 'healthcheck'))
                ->recommendation(__('Utilize somente versões estáveis em produção.', 'healthcheck'))
                ->steps([
                    __('Faça backup completo do banco de dados e dos diretórios de dados.', 'healthcheck'),
                    __('Baixe a versão estável mais recente na página oficial de releases.', 'healthcheck'),
                    __('Siga o procedimento oficial de atualização.', 'healthcheck'),
                ])
                ->doc(self::DOC_UPDATE)
                ->facts($facts);
        }

        if ($releases['source'] === 'none') {
            $result = CheckResult::info(
                sprintf(__('Versão instalada: %s. Não foi possível determinar se existe versão mais recente.', 'healthcheck'), GLPI_VERSION)
            )
                ->cause($releases['error'] !== null
                    ? sprintf(__('Falha na consulta externa: %s', 'healthcheck'), $releases['error'])
                    : __('A consulta externa de versões está desativada e a ação automática "checkupdate" do GLPI não registrou nenhuma versão nova.', 'healthcheck'))
                ->recommendation(__('Verifique manualmente a página oficial de releases ou habilite a consulta externa opcional nas configurações do plugin.', 'healthcheck'))
                ->doc(self::DOC_RELEASES)
                ->facts($facts);
            return $result;
        }

        if ($releases['latest_same_branch'] !== null && version_compare($installed, $releases['latest_same_branch'], '<')) {
            return CheckResult::warning(
                sprintf(
                    __('Há uma versão de manutenção disponível para o ramo %1$s: %2$s (instalada: %3$s).', 'healthcheck'),
                    $branch,
                    $releases['latest_same_branch'],
                    GLPI_VERSION
                ),
                Severity::HIGH
            )
                ->impact(__('Versões de manutenção corrigem falhas de segurança e bugs. Permanecer desatualizado expõe o ambiente a vulnerabilidades conhecidas e publicadas.', 'healthcheck'))
                ->cause(__('A instalação não recebeu as últimas correções publicadas.', 'healthcheck'))
                ->recommendation(__('Planeje a atualização para a última versão do mesmo ramo, que normalmente não exige mudanças de compatibilidade.', 'healthcheck'))
                ->steps([
                    __('Leia o changelog e os avisos de segurança da versão.', 'healthcheck'),
                    __('Resolva os alertas desta página marcados como críticos antes de atualizar.', 'healthcheck'),
                    __('Faça backup completo do banco de dados e dos diretórios de dados e configuração.', 'healthcheck'),
                    __('Substitua os arquivos da aplicação preservando os diretórios config, files, plugins e marketplace.', 'healthcheck'),
                    __('Execute "php bin/console db:update" e valide o acesso.', 'healthcheck'),
                ])
                ->doc(self::DOC_UPDATE)
                ->facts($facts);
        }

        if ($releases['latest'] !== null && version_compare($installed, $releases['latest'], '<')) {
            return CheckResult::info(
                sprintf(
                    __('A versão instalada (%1$s) é a mais recente do seu ramo. Existe uma versão principal mais nova: %2$s.', 'healthcheck'),
                    GLPI_VERSION,
                    $releases['latest']
                )
            )
                ->impact(__('Ramos antigos deixam de receber correções em algum momento. Consulte o ciclo de suporte oficial do GLPI.', 'healthcheck'))
                ->recommendation(__('Planeje a migração para o novo ramo após validar a compatibilidade dos plugins e os requisitos do sistema.', 'healthcheck'))
                ->steps([
                    __('Verifique na aba Plugins quais plugins não declaram compatibilidade com a nova versão.', 'healthcheck'),
                    __('Resolva as migrações de banco de dados pendentes (verificação "Pendências para futuras atualizações").', 'healthcheck'),
                    __('Teste a atualização em um ambiente de homologação antes da produção.', 'healthcheck'),
                ])
                ->doc(self::DOC_UPDATE)
                ->facts($facts);
        }

        return CheckResult::ok(sprintf(__('O GLPI está na versão mais recente conhecida (%s).', 'healthcheck'), GLPI_VERSION))
            ->facts($facts);
    }
}
