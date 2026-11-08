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
use NotImportedEmail;

/**
 * Incoming emails refused by mail collectors (Administration > Not imported emails).
 */
class RejectedMailsCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'email.rejected';
    }

    public function getCategory(): string
    {
        return Category::EMAIL;
    }

    public function getTitle(): string
    {
        return __('E-mails recebidos e não importados', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $table = NotImportedEmail::getTable();
        $total = $this->countRows($table);
        $since = date('Y-m-d H:i:s', $this->now() - WEEK_TIMESTAMP);

        $reasons = [
            NotImportedEmail::MATCH_NO_RULE     => __('Nenhuma regra de entidade correspondente', 'healthcheck'),
            NotImportedEmail::USER_UNKNOWN      => __('Remetente desconhecido', 'healthcheck'),
            NotImportedEmail::FAILED_OPERATION  => __('Falha ao criar o chamado', 'healthcheck'),
            NotImportedEmail::NOT_ENOUGH_RIGHTS => __('Remetente sem permissão', 'healthcheck'),
        ];

        $items = [];
        $recent = 0;
        $iterator = $this->db()->request([
            'SELECT'  => ['reason', $this->expr('COUNT(*) AS nb'), $this->expr('MAX(' . $this->db()->quoteName('date') . ') AS last_date')],
            'FROM'    => $table,
            'WHERE'   => ['date' => ['>', $since]],
            'GROUPBY' => 'reason',
        ]);
        foreach ($iterator as $row) {
            $recent += (int) $row['nb'];
            $items[] = [
                'reason' => $reasons[(int) $row['reason']] ?? (string) $row['reason'],
                'count'  => (int) $row['nb'],
                'last'   => $row['last_date'],
            ];
        }

        $facts = [
            __('Total registrado', 'healthcheck')   => $total,
            __('Últimos 7 dias', 'healthcheck')     => $recent,
        ];
        $columns = [
            'reason' => __('Motivo', 'healthcheck'),
            'count'  => __('Quantidade (7 dias)', 'healthcheck'),
            'last'   => __('Último', 'healthcheck'),
        ];

        if ($recent === 0) {
            return CheckResult::ok(__('Nenhum e-mail recusado pelos coletores nos últimos 7 dias.', 'healthcheck'))
                ->facts($facts);
        }

        $has_failure = count(array_filter(
            $items,
            static fn ($item) => $item['reason'] === __('Falha ao criar o chamado', 'healthcheck')
        )) > 0;

        return CheckResult::warning(
            sprintf(__('%d e-mail(s) recebido(s) não foram importados nos últimos 7 dias.', 'healthcheck'), $recent),
            $has_failure || $recent > 50 ? Severity::MEDIUM : Severity::LOW
        )
            ->impact(__('Solicitações enviadas por e-mail podem não ter gerado chamados e não serão atendidas.', 'healthcheck'))
            ->cause(__('Remetentes não cadastrados quando a criação de chamados por desconhecidos está desativada, ausência de regras de entidade, permissões ou erros na criação do chamado.', 'healthcheck'))
            ->recommendation(__('Revise a lista de e-mails não importados e ajuste regras, usuários ou permissões.', 'healthcheck'))
            ->steps([
                __('Acesse Administração > E-mails não importados (ou Configurar > Coletores > E-mails não importados).', 'healthcheck'),
                __('Importe manualmente as mensagens legítimas usando as ações em massa.', 'healthcheck'),
                __('Revise as regras de "Regras para atribuição de um chamado aberto através de um coletor de e-mails".', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, $columns);
    }
}
