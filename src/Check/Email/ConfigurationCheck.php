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
use Notification;

class ConfigurationCheck extends AbstractCheck
{
    public function getKey(): string
    {
        return 'email.configuration';
    }

    public function getCategory(): string
    {
        return Category::EMAIL;
    }

    public function getTitle(): string
    {
        return __('Configuração de envio de e-mails', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $mode_labels = [
            MAIL_MAIL      => __('PHP mail()', 'healthcheck'),
            MAIL_SMTP      => 'SMTP',
            MAIL_SMTPSSL   => 'SMTP+SSL',
            MAIL_SMTPTLS   => 'SMTP+TLS',
            MAIL_SMTPOAUTH => 'SMTP+OAuth',
        ];
        $mode = (int) $this->cfg('smtp_mode', MAIL_MAIL);

        $facts = [
            __('Notificações habilitadas', 'healthcheck')      => (bool) $this->cfg('use_notifications', 0),
            __('Notificações por e-mail', 'healthcheck')       => (bool) $this->cfg('notifications_mailing', 0),
            __('Modo de envio', 'healthcheck')                 => $mode_labels[$mode] ?? (string) $mode,
            __('Servidor SMTP', 'healthcheck')                 => $mode !== MAIL_MAIL ? $this->cfg('smtp_host') . ':' . $this->cfg('smtp_port') : '-',
            __('Notificações ativas', 'healthcheck')           => $this->countRows(Notification::getTable(), ['is_active' => 1]),
            __('Tentativas máximas de envio', 'healthcheck')   => $this->cfg('smtp_max_retries'),
        ];

        if (!$this->cfg('use_notifications', 0) || !$this->cfg('notifications_mailing', 0)) {
            return CheckResult::info(__('O envio de notificações por e-mail está desativado.', 'healthcheck'))
                ->impact(__('Usuários e técnicos não recebem e-mails sobre chamados, aprovações e alertas.', 'healthcheck'))
                ->recommendation(__('Se o envio de e-mails for esperado, habilite-o em Configurar > Notificações.', 'healthcheck'))
                ->doc(self::DOC_USER)
                ->facts($facts);
        }

        $items    = [];
        $findings = [];
        $add = function (string $status, int $severity, string $setting, string $problem) use (&$items, &$findings) {
            $findings[] = [$status, $severity];
            $items[] = ['setting' => $setting, 'problem' => $problem];
        };

        $admin_email = trim((string) $this->cfg('admin_email', ''));
        if ($admin_email === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $add(Status::WARNING, Severity::HIGH, __('E-mail do administrador', 'healthcheck'), __('Vazio ou inválido: mensagens podem ser rejeitadas pelos servidores de destino.', 'healthcheck'));
        } elseif (preg_match('/@localhost$/i', $admin_email)) {
            $add(Status::WARNING, Severity::MEDIUM, __('E-mail do administrador', 'healthcheck'), __('Usa o valor padrão da instalação (@localhost); servidores de e-mail costumam rejeitar esse remetente.', 'healthcheck'));
        }

        $from_email = trim((string) $this->cfg('from_email', ''));
        if ($from_email !== '' && !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
            $add(Status::WARNING, Severity::MEDIUM, __('E-mail do remetente', 'healthcheck'), __('Endereço inválido.', 'healthcheck'));
        }

        if ($mode === MAIL_MAIL) {
            $add(Status::WARNING, Severity::LOW, __('Modo de envio', 'healthcheck'), __('PHP mail() não autentica nem informa falhas de entrega; as mensagens tendem a ser marcadas como SPAM. Prefira SMTP autenticado com TLS.', 'healthcheck'));
        } else {
            if (trim((string) $this->cfg('smtp_host', '')) === '') {
                $add(Status::CRITICAL, Severity::HIGH, __('Servidor SMTP', 'healthcheck'), __('Modo SMTP selecionado sem servidor configurado: nenhum e-mail será enviado.', 'healthcheck'));
            }
            if ($mode === MAIL_SMTP && trim((string) $this->cfg('smtp_username', '')) !== '') {
                $add(Status::WARNING, Severity::LOW, __('Criptografia SMTP', 'healthcheck'), __('Autenticação SMTP sem SSL/TLS obrigatório: as credenciais podem trafegar sem criptografia.', 'healthcheck'));
            }
            if ($mode !== MAIL_MAIL && !(int) $this->cfg('smtp_check_certificate', 1)) {
                $add(Status::WARNING, Severity::LOW, __('Validação de certificado', 'healthcheck'), __('A validação do certificado do servidor SMTP está desativada, permitindo interceptação.', 'healthcheck'));
            }
            if ($mode === MAIL_SMTPOAUTH && trim((string) $this->cfg('smtp_oauth_refresh_token', '')) === '') {
                $add(Status::CRITICAL, Severity::HIGH, 'OAuth', __('Modo OAuth sem token de atualização: o envio falhará. Refaça a autorização OAuth.', 'healthcheck'));
            }
        }

        if ($facts[__('Notificações ativas', 'healthcheck')] === 0) {
            $add(Status::WARNING, Severity::MEDIUM, __('Notificações', 'healthcheck'), __('Nenhuma notificação está ativa: nenhum evento gerará e-mail.', 'healthcheck'));
        }

        if (count($items) === 0) {
            return CheckResult::ok(__('A configuração de envio de e-mails está adequada.', 'healthcheck'))
                ->facts($facts);
        }

        return CheckResult::worstOf(
            $findings,
            '',
            sprintf(__('%d problema(s) na configuração de envio de e-mails.', 'healthcheck'), count($items))
        )
            ->impact(__('Notificações podem não ser entregues ou ser classificadas como SPAM.', 'healthcheck'))
            ->cause(__('Configuração incompleta ou valores padrão mantidos após a instalação.', 'healthcheck'))
            ->recommendation(__('Revise a configuração em Configurar > Notificações > Configuração das notificações por e-mail e use o botão de envio de teste.', 'healthcheck'))
            ->steps([
                __('Acesse Configurar > Notificações > Configuração das notificações por e-mail.', 'healthcheck'),
                __('Informe e-mails válidos do administrador e do remetente, de um domínio autorizado (SPF/DKIM).', 'healthcheck'),
                __('Prefira SMTP com SSL/TLS e autenticação.', 'healthcheck'),
                __('Clique em "Enviar um e-mail de teste ao administrador" e verifique a entrega.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'setting' => __('Item', 'healthcheck'),
                'problem' => __('Problema', 'healthcheck'),
            ]);
    }
}
