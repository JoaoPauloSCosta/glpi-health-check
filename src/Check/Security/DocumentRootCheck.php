<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Security;

use Glpi\System\Requirement\DataDirectoriesProtectedPath;
use Glpi\System\Requirement\SafeDocumentRoot;
use Glpi\System\Variables;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

/**
 * Exposure of non public files (data, configuration) through the web server.
 */
class DocumentRootCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'security.document_root';
    }

    public function getCategory(): string
    {
        return Category::SECURITY;
    }

    public function getTitle(): string
    {
        return __('Exposição de diretórios pelo servidor web', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Verifica se a raiz do servidor web aponta para "public/" e se os diretórios de dados e configuração estão fora do alcance da web.', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $data_dirs = new DataDirectoriesProtectedPath(Variables::getDataDirectoriesConstants());
        $data_safe = $data_dirs->isValidated();

        $facts = [
            __('Diretório de dados (GLPI_VAR_DIR)', 'healthcheck')   => GLPI_VAR_DIR,
            __('Diretório de configuração', 'healthcheck')           => GLPI_CONFIG_DIR,
            __('Dados fora da raiz da aplicação', 'healthcheck')     => $data_safe,
        ];

        if ($this->isCli()) {
            if ($data_safe) {
                return CheckResult::ok(__('Os diretórios de dados estão fora do diretório da aplicação.', 'healthcheck'))
                    ->facts($facts);
            }
            return CheckResult::skipped(
                __('Os diretórios de dados estão dentro do diretório da aplicação. A configuração da raiz do servidor web só pode ser avaliada em uma execução pelo navegador.', 'healthcheck')
            )->facts($facts);
        }

        $doc_root = new SafeDocumentRoot();
        $facts[__('Raiz do servidor web em public/', 'healthcheck')] = $doc_root->isValidated();

        if ($doc_root->isValidated()) {
            return CheckResult::ok(__('A raiz do servidor web aponta para o diretório "public/": arquivos internos não são acessíveis.', 'healthcheck'))
                ->facts($facts);
        }

        $htaccess_missing = [];
        foreach ([GLPI_VAR_DIR, GLPI_CONFIG_DIR] as $dir) {
            if (is_dir($dir) && !file_exists($dir . '/.htaccess')) {
                $htaccess_missing[] = $dir;
            }
        }

        $result = $data_safe
            ? CheckResult::warning(__('A raiz do servidor web não aponta para "public/", permitindo acesso direto a arquivos internos da aplicação.', 'healthcheck'), Severity::MEDIUM)
            : CheckResult::warning(__('A raiz do servidor web não aponta para "public/" e os diretórios de dados/configuração estão dentro da área acessível pela web.', 'healthcheck'), Severity::HIGH);

        $items = [];
        foreach (array_merge($doc_root->getValidationMessages(), $data_safe ? [] : $data_dirs->getValidationMessages()) as $message) {
            $items[] = ['message' => $message];
        }
        foreach ($htaccess_missing as $dir) {
            $items[] = ['message' => sprintf(__('Arquivo .htaccess de proteção ausente em %s', 'healthcheck'), $dir)];
        }

        return $result
            ->impact(__('Documentos anexados, logs, sessões e até config_db.php podem ser baixados diretamente pela URL se o servidor web não bloquear o acesso (ex.: Nginx ignora .htaccess).', 'healthcheck'))
            ->cause(__('Instalação anterior ao GLPI 10.0.7 ou servidor web configurado com a raiz no diretório principal do GLPI.', 'healthcheck'))
            ->recommendation(__('Configure o DocumentRoot/root do servidor web para o diretório "public/" do GLPI, com regravação de URLs para index.php.', 'healthcheck'))
            ->steps([
                sprintf(__('Apache: "DocumentRoot %s/public" com AllowOverride All (ou as regras de rewrite da documentação).', 'healthcheck'), GLPI_ROOT),
                sprintf(__('Nginx: "root %s/public;" e "location / { try_files $uri /index.php$is_args$args; }".', 'healthcheck'), GLPI_ROOT),
                __('Opcionalmente mova files/ e config/ para fora do diretório da aplicação (ex.: /var/lib/glpi e /etc/glpi) usando config/local_define.php e inc/downstream.php.', 'healthcheck'),
                __('Teste acessando https://seu-glpi/files/_log/php-errors.log: o acesso deve ser negado.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->facts($facts)
            ->items($items, ['message' => __('Detalhes', 'healthcheck')]);
    }
}
