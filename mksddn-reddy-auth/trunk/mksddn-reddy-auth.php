<?php
/**
 * Plugin Name: MksDdn Reddy Auth
 * Plugin URI: https://github.com/mksddn/WP-MksDdn-Reddy-Auth
 * Description: Authentication plugin for Reddy bot OTP login in WordPress and REST API clients.
 * Version: 0.1.0
 * Author: mksddn
 * Author URI: https://github.com/mksddn
 * Text Domain: mksddn-reddy-auth
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MKSDDN_REDDY_AUTH_VERSION', '0.1.0' );
define( 'MKSDDN_REDDY_AUTH_FILE', __FILE__ );
define( 'MKSDDN_REDDY_AUTH_DIR', plugin_dir_path( __FILE__ ) );

require_once MKSDDN_REDDY_AUTH_DIR . 'includes/class-plugin.php';

register_activation_hook( MKSDDN_REDDY_AUTH_FILE, array( 'Mksddn_Reddy_Auth_Plugin', 'activate' ) );
register_deactivation_hook( MKSDDN_REDDY_AUTH_FILE, array( 'Mksddn_Reddy_Auth_Plugin', 'deactivate' ) );

Mksddn_Reddy_Auth_Plugin::instance()->run();
