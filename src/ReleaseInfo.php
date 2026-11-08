<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use Glpi\Toolbox\VersionParser;
use Toolbox;

/**
 * Knowledge about available GLPI releases.
 *
 * Sources, in order:
 *  1. optional external lookup of public GitHub releases (disabled by default, cached 24h);
 *     the request is an anonymous GET: no data about the installation is sent;
 *  2. "founded_new_version" value stored by GLPI's own "checkupdate" automatic action.
 */
final class ReleaseInfo
{
    private const RELEASES_URL = 'https://api.github.com/repos/glpi-project/glpi/releases?per_page=100';
    private const CACHE_TTL    = 86400;

    /** @var array|null */
    private static ?array $info = null;

    /**
     * @return array{source: string, latest: ?string, latest_same_branch: ?string, fetched_at: ?string, error: ?string}
     */
    public static function get(): array
    {
        if (self::$info !== null) {
            return self::$info;
        }

        $info = [
            'source'             => 'none',
            'latest'             => null,
            'latest_same_branch' => null,
            'fetched_at'         => null,
            'error'              => null,
        ];

        if (Settings::allowExternal()) {
            $cache = json_decode((string) Settings::get('release_cache'), true);
            if (is_array($cache) && isset($cache['ts'], $cache['tags']) && (time() - (int) $cache['ts']) < self::CACHE_TTL) {
                $tags = $cache['tags'];
                $info['fetched_at'] = date('Y-m-d H:i:s', (int) $cache['ts']);
            } else {
                $tags = self::fetchStableTags($error);
                if ($tags !== null) {
                    Settings::set(['release_cache' => json_encode(['ts' => time(), 'tags' => $tags])]);
                    $info['fetched_at'] = date('Y-m-d H:i:s');
                } else {
                    $info['error'] = $error;
                }
            }
            if (!empty($tags)) {
                $info['source']             = 'external';
                $info['latest']             = self::latest($tags);
                $info['latest_same_branch'] = self::latest($tags, self::branchOf(GLPI_VERSION));
            }
        }

        if ($info['source'] === 'none') {
            /** @var array $CFG_GLPI */
            global $CFG_GLPI;
            $founded = trim((string) ($CFG_GLPI['founded_new_version'] ?? ''));
            if ($founded !== '' && preg_match('/^\d+\.\d+(\.\d+)*$/', $founded) === 1) {
                $info['source'] = 'glpi';
                $info['latest'] = $founded;
                if (self::branchOf($founded) === self::branchOf(GLPI_VERSION)) {
                    $info['latest_same_branch'] = $founded;
                }
            }
        }

        return self::$info = $info;
    }

    /**
     * "10.0.18" => "10.0"
     */
    public static function branchOf(string $version): string
    {
        $version = VersionParser::getNormalizedVersion($version, false);
        $parts = explode('.', $version);
        return ($parts[0] ?? '0') . '.' . ($parts[1] ?? '0');
    }

    public static function currentVersion(): string
    {
        return VersionParser::getNormalizedVersion(GLPI_VERSION, false);
    }

    /**
     * @param string[] $tags
     */
    private static function latest(array $tags, ?string $branch = null): ?string
    {
        if ($branch !== null) {
            $tags = array_filter($tags, static fn ($tag) => self::branchOf($tag) === $branch);
        }
        if (count($tags) === 0) {
            return null;
        }
        usort($tags, 'version_compare');
        return end($tags) ?: null;
    }

    /**
     * @return string[]|null
     */
    private static function fetchStableTags(?string &$error = null): ?array
    {
        $error = null;
        $content = Toolbox::getURLContent(self::RELEASES_URL, $error);
        if (empty($content)) {
            $error = $error ?: __('Resposta vazia do serviço de versões.', 'healthcheck');
            return null;
        }
        $releases = json_decode($content, true);
        if (!is_array($releases)) {
            $error = __('Resposta inválida do serviço de versões.', 'healthcheck');
            return null;
        }
        $tags = [];
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['prerelease']) || !empty($release['draft'])) {
                continue;
            }
            $tag = ltrim((string) ($release['tag_name'] ?? ''), 'v');
            if (preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $tag) === 1) {
                $tags[] = $tag;
            }
        }
        return count($tags) > 0 ? array_values(array_unique($tags)) : null;
    }
}
