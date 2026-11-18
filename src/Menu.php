<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 * LICENSE: GPLv3+ (see setup.php)
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Healthcheck;

use CommonGLPI;
use Session;

/**
 * Entry in "Setup > Health Check".
 */
class Menu extends CommonGLPI
{
    // No "$rightname" override: GLPI 12 declares it as "public static string" in CommonGLPI, and a
    // redeclaration cannot be compatible with both the untyped (10/11) and typed (12) parent.
    // Access is controlled by canView() below.

    public static function getTypeName($nb = 0)
    {
        return __('Health Check', 'healthcheck');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-heart-rate-monitor';
    }

    // The return type is mandatory since GLPI 12 (CommonGLPI::canView(): bool) and allowed with the
    // untyped parent of GLPI 10/11.
    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function getFrontUrl(string $page, array $params = []): string
    {
        $url = Compat::webDir(true) . '/front/' . $page;
        return count($params) > 0 ? $url . '?' . http_build_query($params) : $url;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $base = '/' . Compat::webDir(false) . '/front/';
        return [
            'title' => self::getMenuName(),
            'page'  => $base . 'dashboard.php',
            'icon'  => self::getIcon(),
        ];
    }
}
