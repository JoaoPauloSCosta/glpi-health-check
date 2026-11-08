<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Database;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;

class ConnectionCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'database.connection';
    }

    public function getCategory(): string
    {
        return Category::DATABASE;
    }

    public function getTitle(): string
    {
        return __('Conexão com o banco de dados', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $db = $this->db();
        if (!$db->connected) {
            return CheckResult::critical(__('Sem conexão com o banco de dados.', 'healthcheck'), Severity::CRITICAL)
                ->impact(__('O GLPI não funciona sem banco de dados.', 'healthcheck'))
                ->recommendation(__('Verifique se o serviço MySQL/MariaDB está ativo e se as credenciais em config/config_db.php estão corretas.', 'healthcheck'))
                ->doc(self::DOC_INSTALL);
        }

        // Round-trip latency (average of 5 trivial queries)
        $samples = [];
        for ($i = 0; $i < 5; $i++) {
            $start = microtime(true);
            $db->doQuery('SELECT 1');
            $samples[] = (microtime(true) - $start) * 1000;
        }
        $latency = array_sum($samples) / count($samples);

        $facts = [
            __('Servidor', 'healthcheck')               => is_array($db->dbhost) ? implode(', ', $db->dbhost) : (string) $db->dbhost,
            __('Base de dados', 'healthcheck')          => $db->dbdefault,
            __('Versão do servidor', 'healthcheck')     => $db->getVersion(),
            __('Latência média (ms)', 'healthcheck')    => number_format($latency, 2, ',', '.'),
            __('Réplica SQL configurada', 'healthcheck') => \DBConnection::isDBSlaveActive(),
        ];

        if ($latency > 50) {
            return CheckResult::warning(
                sprintf(__('Latência elevada com o banco de dados: %s ms por consulta.', 'healthcheck'), number_format($latency, 1, ',', '.')),
                $latency > 200 ? Severity::HIGH : Severity::MEDIUM
            )
                ->impact(__('Cada página do GLPI executa dezenas de consultas; latência alta torna a aplicação lenta.', 'healthcheck'))
                ->cause(__('Banco em servidor remoto com rede lenta, servidor sobrecarregado ou resolução de nomes (DNS) lenta.', 'healthcheck'))
                ->recommendation(__('Aproxime o banco do servidor de aplicação, use IP em vez de nome de host e verifique a carga do servidor de banco.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::ok(__('Conexão com o banco de dados ativa e com boa latência.', 'healthcheck'))
            ->facts($facts);
    }
}
