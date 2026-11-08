<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck\Check\Email;

use Toolbox;

/**
 * Parsing of mail collectors connection strings, e.g. "{imap.example.org:993/imap/ssl/novalidate-cert}INBOX".
 * Logins and passwords are never read nor exposed.
 */
final class MailServerHelper
{
    /**
     * @return array{address: string, port: int, type: string, ssl: bool, tls: bool, novalidate: bool}
     */
    public static function parse(string $connect_string): array
    {
        $tab = Toolbox::parseMailServerConnectString($connect_string);
        $type = (string) ($tab['type'] ?? '');
        $ssl  = !empty($tab['ssl']);
        $port = (int) ($tab['port'] ?? 0);
        if ($port <= 0) {
            if (strpos($type, 'pop') === 0) {
                $port = $ssl ? 995 : 110;
            } else {
                $port = $ssl ? 993 : 143;
            }
        }
        return [
            'address'    => (string) ($tab['address'] ?? ''),
            'port'       => $port,
            'type'       => $type !== '' ? $type : 'imap',
            'ssl'        => $ssl,
            'tls'        => !empty($tab['tls']),
            'novalidate' => stripos($connect_string, '/novalidate-cert') !== false,
        ];
    }

    /**
     * Plain TCP reachability test (no data sent, no authentication).
     *
     * @return array{0: bool, 1: string, 2: float} [reachable, error, elapsed ms]
     */
    public static function tcpProbe(string $host, int $port, int $timeout = 5): array
    {
        if ($host === '' || $port <= 0) {
            return [false, __('Servidor ou porta não informados.', 'healthcheck'), 0.0];
        }
        $start  = microtime(true);
        $errno  = 0;
        $errstr = '';
        $target = sprintf('tcp://%s:%d', strpos($host, ':') !== false ? '[' . $host . ']' : $host, $port);
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout);
        $elapsed = (microtime(true) - $start) * 1000;
        if ($socket === false) {
            return [false, trim($errstr) !== '' ? $errstr : sprintf(__('Erro %d', 'healthcheck'), $errno), $elapsed];
        }
        fclose($socket);
        return [true, '', $elapsed];
    }
}
