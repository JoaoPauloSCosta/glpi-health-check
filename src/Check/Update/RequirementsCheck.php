<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Update;

use Glpi\System\Requirement;
use Glpi\System\RequirementsManager;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

/**
 * Uses GLPI's own requirements manager (same list as the installer and
 * "php bin/console system:check_requirements").
 */
class RequirementsCheck extends AbstractCheck
{
    /**
     * Requirements already covered (with more details) by dedicated checks.
     */
    private const COVERED_ELSEWHERE = [
        Requirement\PhpVersion::class,
        Requirement\PhpSupportedVersion::class,
        Requirement\DbEngine::class,
        Requirement\DirectoriesWriteAccess::class,
        Requirement\DirectoryWriteAccess::class,
        Requirement\LogsWriteAccess::class,
        Requirement\SafeDocumentRoot::class,
        Requirement\DataDirectoriesProtectedPath::class,
        Requirement\SessionsSecurityConfiguration::class,
    ];

    public function getKey(): string
    {
        return 'update.requirements';
    }

    public function getCategory(): string
    {
        return Category::UPDATE;
    }

    public function getTitle(): string
    {
        return __('Requisitos mínimos do sistema', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Extensões PHP, limite de memória, driver do banco, fusos horários e demais requisitos verificados pelo instalador do GLPI.', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $list = (new RequirementsManager())->getCoreRequirementList($this->db());

        $items    = [];
        $findings = [];
        $checked  = 0;
        foreach ($list as $requirement) {
            foreach (self::COVERED_ELSEWHERE as $class) {
                if ($requirement instanceof $class) {
                    continue 2;
                }
            }
            if ($requirement->isOutOfContext()) {
                continue;
            }
            $checked++;
            if ($requirement->isValidated()) {
                continue;
            }
            $optional = $requirement->isOptional();
            $findings[] = $optional ? [Status::WARNING, Severity::LOW] : [Status::CRITICAL, Severity::HIGH];
            $items[] = [
                'requirement' => $requirement->getTitle(),
                'type'        => $optional ? __('Opcional', 'healthcheck') : __('Obrigatório', 'healthcheck'),
                'details'     => implode(' ', $requirement->getValidationMessages()),
            ];
        }

        $columns = [
            'requirement' => __('Requisito', 'healthcheck'),
            'type'        => __('Tipo', 'healthcheck'),
            'details'     => __('Detalhes', 'healthcheck'),
        ];

        if (count($items) === 0) {
            return CheckResult::ok(sprintf(__('Todos os %d requisitos verificados estão atendidos.', 'healthcheck'), $checked));
        }

        $mandatory = count(array_filter($findings, static fn ($f) => $f[0] === Status::CRITICAL));
        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(
                __('%1$d requisito(s) não atendido(s), sendo %2$d obrigatório(s).', 'healthcheck'),
                count($items),
                $mandatory
            )
        )
            ->impact($mandatory > 0
                ? __('Requisitos obrigatórios ausentes podem causar erros, perda de funcionalidades e impedir atualizações.', 'healthcheck')
                : __('Requisitos opcionais ausentes desativam recursos ou reduzem segurança e desempenho.', 'healthcheck'))
            ->cause(__('Extensões PHP não instaladas/habilitadas ou configurações do PHP e do banco abaixo do recomendado.', 'healthcheck'))
            ->recommendation(__('Instale as extensões e ajuste as configurações indicadas, depois reinicie o servidor web/PHP-FPM.', 'healthcheck'))
            ->steps([
                __('Execute "php bin/console system:check_requirements" para ver o detalhamento.', 'healthcheck'),
                __('Instale as extensões ausentes pelo gerenciador de pacotes do sistema (ex.: php-intl, php-gd).', 'healthcheck'),
                __('Reinicie o servidor web ou o PHP-FPM e execute este diagnóstico novamente.', 'healthcheck'),
            ])
            ->doc(self::DOC_PREREQUISITES)
            ->items($items, $columns);
    }
}
