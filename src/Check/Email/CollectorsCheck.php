<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Email;

use GlpiPlugin\Healthcheck\Check\AbstractCheck;
use GlpiPlugin\Healthcheck\Check\Category;
use GlpiPlugin\Healthcheck\Check\CheckResult;
use GlpiPlugin\Healthcheck\Check\Severity;
use GlpiPlugin\Healthcheck\Check\Status;
use GlpiPlugin\Healthcheck\Format;
use MailCollector;

class CollectorsCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'email.collectors';
    }

    public function getCategory(): string
    {
        return Category::EMAIL;
    }

    public function getTitle(): string
    {
        return __('Coletores de e-mail (Receivers)', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $collectors = iterator_to_array($this->db()->request([
            'SELECT' => ['id', 'name', 'host', 'is_active', 'errors', 'last_collect_date', 'date_creation'],
            'FROM'   => MailCollector::getTable(),
            'ORDER'  => 'name',
        ]), false);

        if (count($collectors) === 0) {
            return CheckResult::info(__('Nenhum coletor de e-mail configurado.', 'healthcheck'))
                ->recommendation(__('Se chamados devem ser abertos por e-mail, configure um coletor em Configurar > Coletores.', 'healthcheck'))
                ->doc(self::DOC_USER);
        }

        $items    = [];
        $findings = [];
        $active   = 0;
        foreach ($collectors as $collector) {
            $server = MailServerHelper::parse((string) $collector['host']);
            $problems = [];

            if ((int) $collector['is_active'] === 1) {
                $active++;
                if ((int) $collector['errors'] > 0) {
                    $findings[] = [Status::WARNING, Severity::HIGH];
                    $problems[] = sprintf(__('%d erro(s) de conexão/autenticação registrados', 'healthcheck'), (int) $collector['errors']);
                }
                $last = !empty($collector['last_collect_date']) ? strtotime($collector['last_collect_date']) : null;
                if ($last === null) {
                    $created = !empty($collector['date_creation']) ? strtotime($collector['date_creation']) : 0;
                    if ($this->now() - $created > DAY_TIMESTAMP) {
                        $findings[] = [Status::WARNING, Severity::MEDIUM];
                        $problems[] = __('Nunca coletou mensagens', 'healthcheck');
                    }
                } elseif ($this->now() - $last > DAY_TIMESTAMP) {
                    $findings[] = [Status::WARNING, Severity::MEDIUM];
                    $problems[] = sprintf(__('Sem coleta há %s', 'healthcheck'), Format::duration($this->now() - $last));
                }
                if (!$server['ssl'] && !$server['tls']) {
                    $findings[] = [Status::WARNING, Severity::LOW];
                    $problems[] = __('Conexão sem SSL/TLS (senha trafega sem criptografia)', 'healthcheck');
                }
                if ($server['novalidate']) {
                    $findings[] = [Status::WARNING, Severity::LOW];
                    $problems[] = __('Validação de certificado desativada (/novalidate-cert)', 'healthcheck');
                }
            }

            $items[] = [
                'name'     => $collector['name'],
                'server'   => sprintf('%s:%d (%s%s)', $server['address'], $server['port'], strtoupper($server['type']), $server['ssl'] ? '/SSL' : ($server['tls'] ? '/TLS' : '')),
                'active'   => (int) $collector['is_active'] === 1,
                'errors'   => (int) $collector['errors'],
                'last'     => $collector['last_collect_date'] ?? '-',
                'problems' => count($problems) > 0 ? implode('; ', $problems) : '-',
            ];
        }

        $columns = [
            'name'     => __('Coletor', 'healthcheck'),
            'server'   => __('Servidor', 'healthcheck'),
            'active'   => __('Ativo', 'healthcheck'),
            'errors'   => __('Erros', 'healthcheck'),
            'last'     => __('Última coleta', 'healthcheck'),
            'problems' => __('Problemas', 'healthcheck'),
        ];
        $facts = [
            __('Coletores configurados', 'healthcheck') => count($collectors),
            __('Coletores ativos', 'healthcheck')       => $active,
        ];

        if (count($findings) === 0) {
            return CheckResult::ok(sprintf(__('%d coletor(es) ativo(s) funcionando sem erros.', 'healthcheck'), $active))
                ->facts($facts)
                ->items($items, $columns);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            __('Há coletores de e-mail com erros, sem coleta recente ou com configuração insegura.', 'healthcheck')
        )
            ->impact(__('E-mails enviados pelos usuários não viram chamados ou acompanhamentos; solicitações podem ser perdidas.', 'healthcheck'))
            ->cause(__('Senha alterada ou expirada, autenticação moderna (OAuth) exigida pelo provedor, servidor inacessível, certificado inválido ou ação automática "mailgate" parada.', 'healthcheck'))
            ->recommendation(__('Teste cada coletor e corrija servidor, credenciais e segurança da conexão.', 'healthcheck'))
            ->steps([
                __('Acesse Configurar > Coletores e abra o coletor indicado.', 'healthcheck'),
                __('Use o botão "Testar" (ou "Obter e-mails agora") e observe a mensagem de erro.', 'healthcheck'),
                __('Atualize a senha/credenciais e prefira IMAP com SSL (porta 993).', 'healthcheck'),
                __('Confirme que a ação automática "mailgate" está ativa.', 'healthcheck'),
                __('Após corrigir, o contador de erros é zerado na próxima coleta bem-sucedida.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, $columns);
    }
}
