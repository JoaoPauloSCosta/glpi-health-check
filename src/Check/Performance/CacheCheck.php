<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Performance;

use Glpi\Cache\CacheManager;
use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

class CacheCheck extends AbstractCheck
{
    private const LARGE_INSTALL_USERS = 300;

    public function getKey(): string
    {
        return 'performance.cache';
    }

    public function getCategory(): string
    {
        return Category::PERFORMANCE;
    }

    public function getTitle(): string
    {
        return __('Configuração e funcionamento do cache', 'healthcheck');
    }

    public function run(): CheckResult
    {
        /** @var \Psr\SimpleCache\CacheInterface $GLPI_CACHE */
        global $GLPI_CACHE;

        try {
            $adapter = (new CacheManager())->getCacheStorageAdapter(CacheManager::CONTEXT_CORE);
            $adapter_name = (new \ReflectionClass($adapter))->getShortName();
        } catch (\Throwable $e) {
            $adapter_name = __('Desconhecido', 'healthcheck');
        }
        $backend_labels = [
            'FilesystemAdapter' => __('Sistema de arquivos (padrão)', 'healthcheck'),
            'RedisAdapter'      => 'Redis',
            'MemcachedAdapter'  => 'Memcached',
        ];
        $backend = $backend_labels[$adapter_name] ?? $adapter_name;

        // Round-trip probe with a dedicated, short lived key
        $probe_ok = false;
        $probe_ms = 0.0;
        if ($GLPI_CACHE !== null) {
            $key   = 'plugin_healthcheck_probe';
            $value = bin2hex(random_bytes(8));
            $start = microtime(true);
            try {
                $GLPI_CACHE->set($key, $value, 60);
                $probe_ok = $GLPI_CACHE->get($key) === $value;
                $GLPI_CACHE->delete($key);
            } catch (\Throwable $e) {
                $probe_ok = false;
            }
            $probe_ms = (microtime(true) - $start) * 1000;
        }

        $active_users = $this->countRows('glpi_users', ['is_active' => 1, 'is_deleted' => 0]);

        $facts = [
            __('Backend do cache', 'healthcheck')               => $backend,
            __('Teste de escrita/leitura', 'healthcheck')       => $probe_ok ? __('Sucesso', 'healthcheck') : __('Falhou', 'healthcheck'),
            __('Tempo do teste', 'healthcheck')                 => number_format($probe_ms, 1, ',', '.') . ' ms',
            __('Usuários ativos', 'healthcheck')                => $active_users,
        ];

        if (!$probe_ok) {
            return CheckResult::warning(__('O cache do GLPI não está gravando/lendo dados corretamente.', 'healthcheck'), Severity::HIGH)
                ->impact(__('Sem cache, cada página recalcula menus, direitos e traduções, deixando o GLPI lento; em casos extremos ocorrem erros.', 'healthcheck'))
                ->cause(__('Diretório files/_cache sem permissão de escrita, servidor Redis/Memcached indisponível ou configuração inválida em config/cache.php.', 'healthcheck'))
                ->recommendation(__('Verifique o backend de cache e as permissões do diretório de cache.', 'healthcheck'))
                ->steps([
                    __('Execute "php bin/console cache:debug" para ver a configuração.', 'healthcheck'),
                    __('Limpe o cache: "php bin/console cache:clear".', 'healthcheck'),
                    __('Para Redis/Memcached, verifique se o serviço está ativo e acessível.', 'healthcheck'),
                ])
                ->doc(self::DOC_CLI)
                ->facts($facts);
        }

        if ($adapter_name === 'FilesystemAdapter' && $active_users > self::LARGE_INSTALL_USERS) {
            return CheckResult::warning(
                sprintf(__('Cache em sistema de arquivos em uma instalação com %d usuários ativos.', 'healthcheck'), $active_users),
                Severity::LOW
            )
                ->impact(__('Em instalações maiores o cache em disco gera muita E/S e não é compartilhado entre servidores.', 'healthcheck'))
                ->recommendation(__('Considere um cache em memória (Redis ou Memcached).', 'healthcheck'))
                ->steps([
                    __('Instale o Redis e a extensão PHP "redis".', 'healthcheck'),
                    __('Configure: "php bin/console cache:configure --dsn=redis://127.0.0.1:6379".', 'healthcheck'),
                ])
                ->doc(self::DOC_CLI)
                ->facts($facts);
        }

        return CheckResult::ok(sprintf(__('Cache operacional (%s).', 'healthcheck'), $backend))
            ->facts($facts);
    }
}
