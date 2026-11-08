<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Security;

use Glpi\System\Requirement\SessionsSecurityConfiguration;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

class SessionSecurityCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'security.session_cookies';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Segurança dos cookies de sessão', 'healthcheck');
    }

    public function run(): CheckResult
    {
        if ($this->isCli()) {
            return CheckResult::skipped($this->cliNotice());
        }

        $facts = [
            'session.cookie_secure'   => ini_get('session.cookie_secure') ?: '0',
            'session.cookie_httponly' => ini_get('session.cookie_httponly') ?: '0',
            'session.cookie_samesite' => ini_get('session.cookie_samesite') ?: __('(não definido)', 'healthcheck'),
            'session.use_strict_mode' => ini_get('session.use_strict_mode') ?: '0',
        ];

        $requirement = new SessionsSecurityConfiguration();
        if ($requirement->isValidated()) {
            return CheckResult::ok(__('Os cookies de sessão estão configurados de forma segura.', 'healthcheck'))
                ->facts($facts);
        }

        $items = array_map(static fn ($m) => ['message' => $m], $requirement->getValidationMessages());

        return CheckResult::warning(__('A configuração dos cookies de sessão do PHP não segue as recomendações de segurança.', 'healthcheck'), Severity::MEDIUM)
            ->impact(__('Cookies de sessão podem ser roubados por scripts (XSS), enviados por HTTP sem criptografia ou usados em requisições de outros sites (CSRF).', 'healthcheck'))
            ->cause(__('Valores padrão do php.ini não ajustados para o GLPI.', 'healthcheck'))
            ->recommendation(__('Ajuste as diretivas de sessão no php.ini (ou no pool do PHP-FPM) e reinicie o serviço.', 'healthcheck'))
            ->steps([
                __('session.cookie_httponly = On', 'healthcheck'),
                __('session.cookie_secure = On (quando o acesso for por HTTPS)', 'healthcheck'),
                __('session.cookie_samesite = Lax', 'healthcheck'),
                __('Reinicie o servidor web/PHP-FPM e execute o diagnóstico novamente.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->facts($facts)
            ->items($items, ['message' => __('Detalhes', 'healthcheck')]);
    }
}
