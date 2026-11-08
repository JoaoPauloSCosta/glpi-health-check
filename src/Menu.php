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
use Plugin;
use Session;

/**
 * Entry in "Setup > Health Check".
 */
class Menu extends CommonGLPI
{
    public static $rightname = 'config';

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

    public static function canView()
    {
        return Session::haveRight('config', READ);
    }

    public static function getFrontUrl(string $page, array $params = []): string
    {
        $url = Plugin::getWebDir('healthcheck', true) . '/front/' . $page;
        return count($params) > 0 ? $url . '?' . http_build_query($params) : $url;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $base = '/' . Plugin::getWebDir('healthcheck', false) . '/front/';
        return [
            'title' => self::getMenuName(),
            'page'  => $base . 'dashboard.php',
            'icon'  => self::getIcon(),
        ];
    }
}
