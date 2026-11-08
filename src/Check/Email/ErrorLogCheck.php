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

/**
 * Recent sending failures, read from files/_log/mail-error.log (written by GLPI).
 */
class ErrorLogCheck extends AbstractCheck
{
    /** Only the end of the file is read, to keep the check light. */
    private const TAIL_BYTES = 262144;

    public function getKey(): string
    {
        return 'email.error_log';
    }

    public function getCategory(): string
    {
        return Category::EMAIL;
    }

    public function getTitle(): string
    {
        return __('Falhas de envio e autenticação SMTP', 'healthcheck');
    }

    public function run(): CheckResult
    {
        $file = GLPI_LOG_DIR . '/mail-error.log';
        if (!file_exists($file)) {
            return CheckResult::ok(__('Nenhum registro de falha de envio de e-mail (mail-error.log inexistente).', 'healthcheck'));
        }
        if (!is_readable($file)) {
            return CheckResult::skipped(__('O arquivo mail-error.log existe mas não pode ser lido pelo usuário do PHP.', 'healthcheck'));
        }

        $since   = $this->now() - WEEK_TIMESTAMP;
        $entries = $this->recentEntries($file, $since);

        $auth = 0;
        $connection = 0;
        $last_messages = [];
        foreach ($entries as $entry) {
            if (preg_match('/authenticat|\b53[045]\b|password|credential|login failed/i', $entry['text'])) {
                $auth++;
            } elseif (preg_match('/connect|timed? ?out|refused|resolve|getaddrinfo|ssl|tls|certificate/i', $entry['text'])) {
                $connection++;
            }
            $last_messages[] = $entry;
        }
        $last_messages = array_slice($last_messages, -5);

        $facts = [
            __('Falhas registradas (7 dias)', 'healthcheck')      => count($entries),
            __('Falhas de autenticação', 'healthcheck')            => $auth,
            __('Falhas de conexão/criptografia', 'healthcheck')    => $connection,
            __('Última modificação do log', 'healthcheck')         => date('Y-m-d H:i:s', (int) filemtime($file)),
        ];

        if (count($entries) === 0) {
            return CheckResult::ok(__('Nenhuma falha de envio de e-mail registrada nos últimos 7 dias.', 'healthcheck'))
                ->facts($facts);
        }

        $items = array_map(
            static fn ($entry) => [
                'date'    => $entry['date'],
                'message' => mb_substr(preg_replace('/\s+/', ' ', $entry['text']), 0, 300),
            ],
            array_reverse($last_messages)
        );

        $severity = $auth > 0 ? Severity::HIGH : (count($entries) > 20 ? Severity::HIGH : Severity::MEDIUM);

        return CheckResult::warning(
            sprintf(__('%d falha(s) de envio de e-mail registrada(s) nos últimos 7 dias.', 'healthcheck'), count($entries)),
            $severity
        )
            ->impact(__('Notificações não entregues; após o número máximo de tentativas, o GLPI desiste da mensagem.', 'healthcheck'))
            ->cause($auth > 0
                ? __('O servidor SMTP recusou a autenticação: senha alterada/expirada, conta bloqueada ou autenticação básica desabilitada pelo provedor.', 'healthcheck')
                : __('Servidor SMTP inacessível, porta bloqueada por firewall, problema de certificado ou destinatários inválidos.', 'healthcheck'))
            ->recommendation(__('Corrija a configuração SMTP e envie um e-mail de teste.', 'healthcheck'))
            ->steps([
                __('Leia as mensagens completas em files/_log/mail-error.log.', 'healthcheck'),
                __('Revise servidor, porta, criptografia e credenciais em Configurar > Notificações > Configuração das notificações por e-mail.', 'healthcheck'),
                __('Para Microsoft 365/Google, considere o modo SMTP+OAuth.', 'healthcheck'),
                __('Envie um e-mail de teste ao administrador.', 'healthcheck'),
            ])
            ->doc(self::DOC_USER)
            ->facts($facts)
            ->items($items, [
                'date'    => __('Data', 'healthcheck'),
                'message' => __('Mensagem (últimas)', 'healthcheck'),
            ]);
    }

    /**
     * Log entries start with "Y-m-d H:i:s [user@host]".
     *
     * @return array<int, array{date: string, text: string}>
     */
    private function recentEntries(string $file, int $since): array
    {
        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($size > self::TAIL_BYTES) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        $entries = [];
        $current = null;
        foreach (preg_split('/\R/', $content) as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['date' => $matches[1], 'text' => ''];
                continue;
            }
            if ($current !== null && trim($line) !== '') {
                $current['text'] .= ($current['text'] === '' ? '' : ' ') . trim($line);
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        return array_values(array_filter(
            $entries,
            static fn ($entry) => strtotime($entry['date']) >= $since
        ));
    }
}
