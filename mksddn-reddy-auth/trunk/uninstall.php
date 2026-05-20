<?php
/**
 * Uninstall cleanup for MksDdn Reddy Auth plugin.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = array(
	'mksddn_reddy_auth_version',
	'mksddn_reddy_auth_settings',
	'mksddn_reddy_auth_auto_create_users',
	'mksddn_reddy_auth_bot_token',
);

foreach ( $options as $option_key ) {
	delete_option( $option_key );
	delete_site_option( $option_key );
}

// Delete plugin-owned user meta mappings.
delete_metadata( 'user', 0, '_mksddn_reddy_id', '', true );
delete_metadata( 'user', 0, '_mksddn_reddy_profile_hash', '', true );

$table_name = $wpdb->prefix . 'mksddn_reddy_tokens';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
