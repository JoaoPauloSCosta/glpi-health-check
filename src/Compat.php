<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use DBmysql;
use Plugin;
use Session;

/**
 * Single place for everything that differs between the supported GLPI versions (10.0, 11.0 and 12.0).
 *
 * Feature detection (class/method existence) is preferred. A version comparison is only used
 * where the same API exists everywhere but behaves differently.
 *
 * This file must stay parsable by PHP 7.4, the minimum PHP version of GLPI 10.0.
 */
final class Compat
{
    private const PLUGIN_KEY = 'healthcheck';

    /**
     * Whether the running GLPI is at least the given version (pre-release suffixes are ignored).
     */
    public static function isAtLeast(string $version): bool
    {
        $current = (string) preg_replace('/^(\d+(?:\.\d+)*).*$/', '$1', GLPI_VERSION);
        return version_compare($current, $version, '>=');
    }

    // ---------------------------------------------------------------------
    // URLs and static resources
    // ---------------------------------------------------------------------

    /**
     * Web path of the plugin.
     *
     * Since GLPI 11 every plugin is served from "/plugins/<key>", wherever it is installed
     * (plugins or marketplace directory), and Plugin::getWebDir() is deprecated (removed in GLPI 12).
     *
     * @param bool $full Prepend the GLPI root path (false returns a path relative to it, without leading slash)
     */
    public static function webDir(bool $full = true): string
    {
        if (!self::isAtLeast('11.0.0') && method_exists(Plugin::class, 'getWebDir')) {
            $dir = Plugin::getWebDir(self::PLUGIN_KEY, $full);
            if (is_string($dir) && $dir !== '') {
                return $dir;
            }
        }

        $path = 'plugins/' . self::PLUGIN_KEY;
        if (!$full) {
            return $path;
        }

        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/' . $path;
    }

    /**
     * URL of a static resource stored in the "public" directory of the plugin.
     *
     * GLPI 11+ only exposes the "public" directory of a plugin and maps it to the plugin root URL.
     * GLPI 10 serves the plugin directory as is, so "/public" has to be part of the URL.
     *
     * @param string $path Path relative to the "public" directory (e.g. "css/healthcheck.css")
     */
    public static function assetUrl(string $path): string
    {
        $path = ltrim($path, '/');
        $url  = self::webDir() . (self::isAtLeast('11.0.0') ? '/' : '/public/') . $path;

        $mtime = @filemtime(PLUGIN_HEALTHCHECK_DIR . '/public/' . $path);
        return $url . '?v=' . (int) $mtime;
    }

    // ---------------------------------------------------------------------
    // HTTP layer
    // ---------------------------------------------------------------------

    /**
     * CSRF token to embed in the plugin forms, or null when no token must be sent.
     *
     * GLPI 10 and 11 validate a per-request token on every POST. GLPI 12 replaced it with
     * request headers validation and deprecates the token API.
     */
    public static function csrfToken(): ?string
    {
        if (self::isAtLeast('12.0.0') || !method_exists(Session::class, 'getNewCSRFToken')) {
            return null;
        }
        return (string) Session::getNewCSRFToken();
    }

    /**
     * Stop the request with a "not found" error.
     *
     * Html::displayNotFoundError() is deprecated in GLPI 11 and removed in GLPI 12.
     *
     * @return void
     */
    public static function notFound()
    {
        $exception = 'Glpi\\Exception\\Http\\NotFoundHttpException';
        if (class_exists($exception)) {
            throw new $exception();
        }
        \Html::displayNotFoundError();
        exit;
    }

    /**
     * Fetch the content of an external URL, using GLPI proxy settings.
     *
     * Toolbox::getURLContent() was removed in GLPI 12 in favor of Glpi\Toolbox\HttpClient.
     *
     * @param string      $url
     * @param string|null $error Filled with the error message on failure
     *
     * @return string Empty string on failure
     */
    public static function fetchUrl(string $url, ?string &$error = null): string
    {
        $error = null;

        $client_class = 'Glpi\\Toolbox\\HttpClient';
        if (class_exists($client_class)) {
            try {
                $response = (new $client_class())->get($url, [
                    'timeout' => 10,
                    'headers' => [
                        'Accept'     => 'application/vnd.github+json, application/json',
                        'User-Agent' => 'GLPI-HealthCheck',
                    ],
                ]);
                return (string) $response->getContent();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                return '';
            }
        }

        if (method_exists(\Toolbox::class, 'getURLContent')) {
            $content = \Toolbox::getURLContent($url, $error);
            return is_string($content) ? $content : '';
        }

        $error = 'No HTTP client available';
        return '';
    }

    // ---------------------------------------------------------------------
    // Database layer
    // ---------------------------------------------------------------------

    /**
     * Raw SQL expression usable in a query built by the GLPI query builder.
     *
     * The QueryExpression class moved to the Glpi\DBAL namespace in GLPI 11; the global one was removed in GLPI 12.
     *
     * @return object
     */
    public static function expr(string $sql)
    {
        $class = class_exists('Glpi\\DBAL\\QueryExpression') ? 'Glpi\\DBAL\\QueryExpression' : 'QueryExpression';
        return new $class($sql);
    }

    /**
     * Execute a raw SQL query written by the plugin itself (never built from user input).
     *
     * DBmysql::doQuery() exists since GLPI 10.0.11 and throws on failure since GLPI 11;
     * the "OrDie" variants are removed in GLPI 12.
     *
     * @return \mysqli_result|bool
     *
     * @throws \RuntimeException When the query fails
     */
    public static function query(DBmysql $db, string $sql)
    {
        // DBmysql::query() is the only option before GLPI 10.0.11
        $method = method_exists($db, 'doQuery') ? 'doQuery' : 'query';
        $result = $db->{$method}($sql);
        if ($result === false) {
            throw new \RuntimeException(self::dbError($db));
        }
        return $result;
    }

    /**
     * Last error reported by the database connection.
     */
    public static function dbError(DBmysql $db): string
    {
        return method_exists($db, 'error') ? (string) $db->error() : '';
    }

    /**
     * Whether string values must be escaped by the caller before DBmysql::insert()/update().
     *
     * GLPI 10 expects already escaped values (input auto-sanitize era). Since GLPI 11 the query
     * builder escapes the values itself, so escaping them twice would store corrupted data.
     */
    public static function mustEscapeValues(): bool
    {
        return !self::isAtLeast('11.0.0');
    }

    /**
     * Whether a SQL replica is configured, or null when the information is not available.
     *
     * DBConnection::isDBSlaveActive() was removed in GLPI 12.
     */
    public static function isDbReplicaActive(): ?bool
    {
        if (method_exists(\DBConnection::class, 'isDBSlaveActive')) {
            return (bool) \DBConnection::isDBSlaveActive();
        }
        if (defined('GLPI_CONFIG_DIR')) {
            return is_file(GLPI_CONFIG_DIR . '/config_db_slave.php');
        }
        return null;
    }
}
