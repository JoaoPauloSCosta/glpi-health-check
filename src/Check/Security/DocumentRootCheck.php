<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Security;

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
        [$data_safe, $data_messages] = $this->checkDataDirectories();

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

        [$root_safe, $root_messages] = $this->checkDocumentRoot();
        $facts[__('Raiz do servidor web em public/', 'healthcheck')] = $root_safe;

        if ($root_safe) {
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
        foreach (array_merge($root_messages, $data_safe ? [] : $data_messages) as $message) {
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

    /**
     * Whether the data directories are located outside the GLPI root directory.
     *
     * Relies on the GLPI requirement class when it exists (GLPI 10.0); it was removed in GLPI 11,
     * where the same evaluation is done here.
     *
     * @return array [bool $safe, string[] $messages]
     */
    private function checkDataDirectories(): array
    {
        $class = 'Glpi\\System\\Requirement\\DataDirectoriesProtectedPath';
        if (class_exists($class)) {
            $requirement = new $class(Variables::getDataDirectoriesConstants());
            return [(bool) $requirement->isValidated(), (array) $requirement->getValidationMessages()];
        }

        $root      = $this->normalizePath(GLPI_ROOT);
        $constants = array_unique(array_merge(['GLPI_VAR_DIR'], Variables::getDataDirectoriesConstants()));
        $messages  = [];
        foreach ($constants as $constant) {
            if (!defined($constant)) {
                continue;
            }
            $directory = (string) constant($constant);
            if (!is_dir($directory)) {
                continue;
            }
            if (strpos($this->normalizePath($directory), $root) === 0) {
                $messages[] = sprintf('"%s" (%s)', $directory, $constant);
            }
        }
        return [count($messages) === 0, $messages];
    }

    /**
     * Whether the web server exposes only the "public" directory of GLPI.
     *
     * Relies on the GLPI requirement class when it exists (GLPI 10.0); it was removed in GLPI 11,
     * where the same evaluation (entry script of the request) is done here.
     *
     * @return array [bool $safe, string[] $messages]
     */
    private function checkDocumentRoot(): array
    {
        $class = 'Glpi\\System\\Requirement\\SafeDocumentRoot';
        if (class_exists($class)) {
            $requirement = new $class();
            return [(bool) $requirement->isValidated(), (array) $requirement->getValidationMessages()];
        }

        $root     = $this->normalizePath(GLPI_ROOT);
        $included = get_included_files();
        $entry    = array_shift($included);
        // Skip scripts loaded before the application (auto_prepend_file)
        while ($entry !== null && strpos($this->normalizePath($entry), $root) !== 0) {
            $entry = array_shift($included);
        }

        $safe = $entry !== null
            && $this->normalizePath($entry) === $this->normalizePath(GLPI_ROOT . '/public/index.php');

        return [$safe, []];
    }

    /**
     * Real path with forward slashes, and a trailing slash for directories (safe prefix comparisons).
     */
    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        $real = str_replace('\\', '/', $real !== false ? $real : $path);
        return is_dir($path) ? rtrim($real, '/') . '/' : $real;
    }
}
