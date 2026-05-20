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

$mksddn_reddy_options = array(
	'mksddn_reddy_auth_version',
	'mksddn_reddy_auth_settings',
	'mksddn_reddy_auth_auto_create_users',
	'mksddn_reddy_auth_bot_token',
);

foreach ( $mksddn_reddy_options as $mksddn_reddy_option_key ) {
	delete_option( $mksddn_reddy_option_key );
	delete_site_option( $mksddn_reddy_option_key );
}

// Delete plugin-owned user meta mappings.
delete_metadata( 'user', 0, '_mksddn_reddy_id', '', true );
delete_metadata( 'user', 0, '_mksddn_reddy_profile_hash', '', true );

$mksddn_reddy_table_name = $wpdb->prefix . 'mksddn_reddy_tokens';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- uninstall drops plugin-owned table; name is $wpdb->prefix + fixed suffix.
$wpdb->query( "DROP TABLE IF EXISTS `{$mksddn_reddy_table_name}`" );
