<?php

/**
 * -------------------------------------------------------------------------
 * Health Check plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Health Check.
 *
 * Health Check is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Health Check is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 João Paulo S. Costa
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/JoaoPauloSCosta/glpi-health-check
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Healthcheck\Menu;

define('PLUGIN_HEALTHCHECK_VERSION', '1.0.0');
// Minimal GLPI version, inclusive
define('PLUGIN_HEALTHCHECK_MIN_GLPI_VERSION', '10.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_HEALTHCHECK_MAX_GLPI_VERSION', '10.0.99');

define('PLUGIN_HEALTHCHECK_DIR', __DIR__);

// PSR-4 autoloader for the plugin namespace (no composer dependency required).
spl_autoload_register(static function (string $classname): void {
    $prefix = 'GlpiPlugin\\Healthcheck\\';
    if (strpos($classname, $prefix) !== 0) {
        return;
    }
    $relative = substr($classname, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * Init hooks of the plugin.
 *
 * @return void
 */
function plugin_init_healthcheck()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['healthcheck'] = true;

    if (!Plugin::isPluginActive('healthcheck')) {
        return;
    }

    // Menu entry under "Setup > Health Check"
    $PLUGIN_HOOKS['menu_toadd']['healthcheck'] = ['config' => Menu::class];

    // "Configure" link on the plugins list
    $PLUGIN_HOOKS['config_page']['healthcheck'] = 'front/config.php';
}

/**
 * Get the name and the version of the plugin.
 *
 * @return array
 */
function plugin_version_healthcheck()
{
    return [
        'name'           => 'Health Check',
        'version'        => PLUGIN_HEALTHCHECK_VERSION,
        'author'         => 'João Paulo S. Costa',
        'license'        => 'GPLv3+',
        'homepage'       => 'https://github.com/JoaoPauloSCosta/glpi-health-check',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_HEALTHCHECK_MIN_GLPI_VERSION,
                'max' => PLUGIN_HEALTHCHECK_MAX_GLPI_VERSION,
            ],
            'php'  => [
                'min' => '7.4.0',
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install.
 *
 * @return boolean
 */
function plugin_healthcheck_check_prerequisites()
{
    return true;
}

/**
 * Check configuration process.
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 *
 * @return boolean
 */
function plugin_healthcheck_check_config($verbose = false)
{
    return true;
}
