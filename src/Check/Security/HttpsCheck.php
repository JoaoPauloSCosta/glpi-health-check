<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Security;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

class HttpsCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'security.https';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('HTTPS e URL da aplicação', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $url_base     = (string) $this->cfg('url_base', '');
        $url_base_api = (string) $this->cfg('url_base_api', '');
        $scheme       = strtolower((string) parse_url($url_base, PHP_URL_SCHEME));
        $host         = strtolower((string) parse_url($url_base, PHP_URL_HOST));

        $request_https = null;
        if (!$this->isCli()) {
            $request_https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        }

        $facts = [
            __('URL da aplicação', 'healthcheck')          => $url_base,
            __('URL da API', 'healthcheck')                => $url_base_api !== '' ? $url_base_api : '-',
            __('Requisição atual em HTTPS', 'healthcheck') => $request_https === null ? __('Não avaliado (CLI)', 'healthcheck') : $request_https,
        ];

        $findings = [];
        $items    = [];
        $is_local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($scheme !== 'https') {
            $findings[] = [Status::WARNING, $is_local ? Severity::LOW : Severity::HIGH];
            $items[] = [
                'item'    => __('URL da aplicação', 'healthcheck'),
                'problem' => __('Configurada com HTTP: senhas e cookies de sessão trafegam sem criptografia e os links das notificações usam HTTP.', 'healthcheck'),
            ];
        }
        if ($url_base_api !== '' && strtolower((string) parse_url($url_base_api, PHP_URL_SCHEME)) !== 'https' && (int) $this->cfg('enable_api', 0)) {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
            $items[] = [
                'item'    => __('URL da API', 'healthcheck'),
                'problem' => __('API habilitada sem HTTPS: tokens de acesso trafegam sem criptografia.', 'healthcheck'),
            ];
        }
        // Configured URL vs URL actually used to reach GLPI (web context only)
        if (!$this->isCli() && !empty($_SERVER['HTTP_HOST'])) {
            $request_host = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST']));
            $request_host = trim(explode(',', $request_host)[0]);
            $current      = $request_host . rtrim((string) $this->cfg('root_doc', ''), '/');
            $configured   = strtolower((string) parse_url($url_base, PHP_URL_HOST))
                . (parse_url($url_base, PHP_URL_PORT) ? ':' . parse_url($url_base, PHP_URL_PORT) : '')
                . rtrim((string) parse_url($url_base, PHP_URL_PATH), '/');
            $facts[__('URL de acesso atual', 'healthcheck')] = ($request_https ? 'https://' : 'http://') . $current;
            if ($configured !== strtolower($current)) {
                $findings[] = [Status::WARNING, Severity::MEDIUM];
                $items[] = [
                    'item'    => __('URL da aplicação', 'healthcheck'),
                    'problem' => sprintf(
                        __('A URL configurada (%1$s) difere da URL usada para acessar o GLPI (%2$s): links das notificações e de documentos podem apontar para o endereço errado. Ignore se o acesso passar por um proxy reverso com outro endereço.', 'healthcheck'),
                        $url_base,
                        ($request_https ? 'https://' : 'http://') . $current
                    ),
                ];
            }
        }

        if ($request_https === false && $scheme === 'https') {
            $findings[] = [Status::WARNING, Severity::MEDIUM];
            $items[] = [
                'item'    => __('Acesso atual', 'healthcheck'),
                'problem' => __('A URL configurada usa HTTPS, mas esta página foi acessada por HTTP: falta redirecionamento HTTP → HTTPS.', 'healthcheck'),
            ];
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('O GLPI está configurado para HTTPS.', 'healthcheck'))->facts($facts);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            __('O acesso ao GLPI não está protegido integralmente por HTTPS ou a URL da aplicação está incorreta.', 'healthcheck')
        )
            ->impact(__('Credenciais, cookies de sessão e dados de chamados podem ser interceptados na rede.', 'healthcheck'))
            ->cause(__('Certificado TLS não configurado no servidor web ou URL da aplicação não atualizada após habilitar HTTPS.', 'healthcheck'))
            ->recommendation(__('Habilite HTTPS no servidor web, redirecione HTTP para HTTPS e atualize a URL da aplicação.', 'healthcheck'))
            ->steps([
                __('Instale um certificado TLS válido (ex.: Let\'s Encrypt ou certificado interno).', 'healthcheck'),
                __('Configure o redirecionamento permanente de HTTP para HTTPS no servidor web.', 'healthcheck'),
                __('Atualize "URL da aplicação" em Configurar > Geral > Configuração geral.', 'healthcheck'),
                __('Após o HTTPS, ative session.cookie_secure no PHP.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->facts($facts)
            ->items($items, [
                'item'    => __('Item', 'healthcheck'),
                'problem' => __('Problema', 'healthcheck'),
            ]);
    }
}
