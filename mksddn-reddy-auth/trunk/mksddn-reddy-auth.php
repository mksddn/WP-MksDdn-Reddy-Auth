<?php
/**
 * Plugin Name: MksDdn Reddy Auth
 * Plugin URI: https://example.com
 * Description: Authentication plugin for Reddy bot OTP login in WordPress and REST API clients.
 * Version: 0.1.0
 * Author: mksddn
 * Text Domain: mksddn-reddy-auth
 * Domain Path: /languages
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
