<?php
/**
 * Token repository for plugin access tokens.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Token_Repository {
	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'mksddn_reddy_tokens';
	}

	/**
	 * Create or update tokens table schema.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $this->table_name;
		$sql             = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			last_used_at DATETIME NULL,
			ip_hash CHAR(64) NULL,
			ua_hash CHAR(64) NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Persist hashed token record.
	 *
	 * @param array<string, mixed> $data Prepared data.
	 * @return bool
	 */
	public function insert( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			$this->table_name,
			$data,
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return false !== $inserted;
	}

	/**
	 * Find active token record by hash.
	 *
	 * @param string $token_hash Token hash.
	 * @return object|null
	 */
	public function find_active_by_hash( $token_hash ) {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table_name}
			WHERE token_hash = %s
				AND revoked_at IS NULL
				AND expires_at > %s
			LIMIT 1",
			$token_hash,
			$now
		);

		return $wpdb->get_row( $sql );
	}

	/**
	 * Revoke token by hash.
	 *
	 * @param string $token_hash Token hash.
	 * @return void
	 */
	public function revoke_by_hash( $token_hash ) {
		global $wpdb;

		$wpdb->update(
			$this->table_name,
			array(
				'revoked_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'token_hash' => $token_hash,
			),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Update last usage timestamp.
	 *
	 * @param string $token_hash Token hash.
	 * @return void
	 */
	public function touch_last_used( $token_hash ) {
		global $wpdb;

		$wpdb->update(
			$this->table_name,
			array(
				'last_used_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'token_hash' => $token_hash,
			),
			array( '%s' ),
			array( '%s' )
		);
	}
}
