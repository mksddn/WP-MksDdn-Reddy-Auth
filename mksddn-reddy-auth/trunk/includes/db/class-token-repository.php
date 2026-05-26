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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom token table has no WP API wrapper.
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

		$now        = gmdate( 'Y-m-d H:i:s' );
		$table_name = $this->table_name;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-owned, not user input.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_name}
			WHERE token_hash = %s
				AND revoked_at IS NULL
				AND expires_at > %s
			LIMIT 1",
			$token_hash,
			$now
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query prepared above.
		return $wpdb->get_row( $sql );
	}

	/**
	 * Revoke all active tokens for a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function revoke_all_for_user( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$table_name = $this->table_name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom token table has no WP API wrapper.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table_name}
				SET revoked_at = %s
				WHERE user_id = %d
					AND revoked_at IS NULL",
				gmdate( 'Y-m-d H:i:s' ),
				$user_id
			)
		);
	}

	/**
	 * Revoke token by hash.
	 *
	 * @param string $token_hash Token hash.
	 * @return void
	 */
	public function revoke_by_hash( $token_hash ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom token table has no WP API wrapper.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom token table has no WP API wrapper.
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
