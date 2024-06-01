<?php

/**
 * -----------------------------------------------------------------------------
 * Plugin Name:  ClassicPress Directory Integration
 * Description:  Install and update plugins from ClassicPress directory and keep ClassicPress themes updated.
 * Version:      0.3.1
 * Author:       ClassicPress Contributors
 * Author URI:   https://www.classicpress.net
 * Plugin URI:   https://www.classicpress.net
 * Text Domain:  classicpress-directory-integration
 * Domain Path:  /languages
 * Requires PHP: 7.4
 * Requires CP:  2.0
 * -----------------------------------------------------------------------------
 * This is free software released under the terms of the General Public License,
 * version 2, or later. It is distributed WITHOUT ANY WARRANTY; without even the
 * implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. Full
 * text of the license is available at https://www.gnu.org/licenses/gpl-2.0.txt.
 * -----------------------------------------------------------------------------
 */

// Declare the namespace.
namespace ClassicPress\Directory;

// Prevent direct access.
if (!defined('ABSPATH')) {
	die();
}

const DB_VERSION = 1;

// Load non namespaced constants and functions
require_once 'includes/constants.php';
require_once 'includes/functions.php';

// Load Helpers trait.
require_once 'classes/Helpers.trait.php';

// Load Plugin Update functionality class.
require_once 'classes/PluginUpdate.class.php';
$plugin_update = new PluginUpdate();

// Load Plugin Install functionality class.
require_once 'classes/PluginInstall.class.php';
$plugin_install = new PluginInstall();

// Load Theme Update functionality class.
require_once 'classes/ThemeUpdate.class.php';
$theme_update = new ThemeUpdate();

// Load Theme Install functionality class.
require_once 'classes/ThemeInstall.class.php';
$theme_install = new ThemeInstall();

// Register text domain
function register_text_domain() {
	load_plugin_textdomain('classicpress-directory-integration', false, dirname(plugin_basename(__FILE__)).'/languages');
}
add_action('plugins_loaded', '\ClassicPress\Directory\register_text_domain');

// Add commands to WP-CLI
require_once 'classes/WPCLI.class.php';
if (defined('WP_CLI') && WP_CLI) {
	\WP_CLI::add_command('cpdi', '\ClassicPress\Directory\CPDICLI');
}
