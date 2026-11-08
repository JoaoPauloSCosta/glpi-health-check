<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Filesystem;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;

class WritableDirectoriesCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'filesystem.writable';
    }

    public function getCategory(): string
    {
        return Category::FILESYSTEM;
    }

    public function getTitle(): string
    {
        return __('Acesso aos diretórios do GLPI', 'healthcheck');
    }

    public function getDescription(): string
    {
        return __('Existência, leitura e escrita dos diretórios de dados, cache, sessões, logs, plugins, marketplace e configuração.', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $user = DirectoryHelper::processUser();

        $items    = [];
        $findings = [];
        foreach (DirectoryHelper::getDirectories() as $constant => $dir) {
            $path     = $dir['path'];
            $exists   = is_dir($path);
            $readable = $exists && is_readable($path);
            $writable = $exists && is_writable($path);

            $problem = '-';
            if (!$exists) {
                $problem = __('Diretório inexistente', 'healthcheck');
                $findings[] = $dir['write'] === DirectoryHelper::WRITE_OPTIONAL
                    ? [Status::WARNING, Severity::LOW]
                    : [Status::CRITICAL, Severity::HIGH];
            } elseif (!$readable) {
                $problem = __('Sem permissão de leitura', 'healthcheck');
                $findings[] = [Status::CRITICAL, Severity::HIGH];
            } elseif (!$writable && $dir['write'] === DirectoryHelper::WRITE_REQUIRED) {
                $problem = __('Sem permissão de escrita (necessária)', 'healthcheck');
                $findings[] = [Status::CRITICAL, Severity::HIGH];
            } elseif (!$writable && $dir['write'] === DirectoryHelper::WRITE_OPTIONAL) {
                $problem = __('Sem permissão de escrita: instalação de plugins pelo marketplace indisponível', 'healthcheck');
                $findings[] = [Status::WARNING, Severity::LOW];
            }

            $items[] = [
                'directory' => $dir['label'],
                'path'      => $path,
                'exists'    => $exists,
                'read'      => $readable,
                'write'     => $writable,
                'needed'    => $dir['write'] === DirectoryHelper::WRITE_REQUIRED
                    ? __('Obrigatória', 'healthcheck')
                    : ($dir['write'] === DirectoryHelper::WRITE_OPTIONAL ? __('Opcional', 'healthcheck') : __('Não necessária', 'healthcheck')),
                'owner'     => $exists ? DirectoryHelper::owner($path) : '-',
                'mode'      => $exists ? DirectoryHelper::mode($path) : '-',
                'problem'   => $problem,
            ];
        }

        $columns = [
            'directory' => __('Diretório', 'healthcheck'),
            'path'      => __('Caminho', 'healthcheck'),
            'exists'    => __('Existe', 'healthcheck'),
            'read'      => __('Leitura', 'healthcheck'),
            'write'     => __('Escrita', 'healthcheck'),
            'needed'    => __('Escrita necessária', 'healthcheck'),
            'owner'     => __('Dono', 'healthcheck'),
            'mode'      => __('Permissões', 'healthcheck'),
            'problem'   => __('Problema', 'healthcheck'),
        ];
        $facts = [
            __('Usuário que executa o PHP', 'healthcheck') => $user,
            __('Contexto', 'healthcheck')                  => $this->isCli() ? 'CLI' : PHP_SAPI,
        ];

        if (count($findings) === 0) {
            return CheckResult::ok(__('Todos os diretórios estão acessíveis com as permissões necessárias.', 'healthcheck'))
                ->facts($facts)
                ->items($items, $columns);
        }

        $cause = __('Proprietário ou permissões incorretos após cópia/restauração de arquivos, ou execução do cron com outro usuário (ex.: root), criando arquivos que o servidor web não consegue alterar.', 'healthcheck');
        if ($this->isCli()) {
            $cause .= ' ' . __('Atenção: diagnóstico executado em CLI; o usuário do cron pode ser diferente do usuário do servidor web.', 'healthcheck');
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d diretório(s) com problema de acesso.', 'healthcheck'), count($findings))
        )
            ->impact(__('Falhas ao anexar documentos, gerar cache, gravar sessões (usuários deslogados), registrar logs ou executar o cron.', 'healthcheck'))
            ->cause($cause)
            ->recommendation(__('Ajuste dono e permissões para que o usuário do servidor web tenha leitura e escrita nos diretórios de dados.', 'healthcheck'))
            ->steps([
                __('Identifique o usuário do servidor web (ex.: www-data, apache, nginx).', 'healthcheck'),
                __('Linux: "chown -R www-data:www-data /caminho/files" e "find /caminho/files -type d -exec chmod 755 {} \\;".', 'healthcheck'),
                __('Windows: conceda "Modificar" ao usuário do serviço do servidor web nas propriedades de segurança da pasta.', 'healthcheck'),
                __('Execute o cron com o mesmo usuário do servidor web.', 'healthcheck'),
            ])
            ->doc(self::DOC_INSTALL)
            ->facts($facts)
            ->items($items, $columns);
    }
}
