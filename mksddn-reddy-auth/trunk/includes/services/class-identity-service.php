<?php
/**
 * Resolve and map Reddy identity to WordPress users.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Identity_Service {
	/**
	 * Meta key for Reddy ID mapping.
	 *
	 * @var string
	 */
	const REDDY_ID_META_KEY = '_mksddn_reddy_id';

	/**
	 * Meta key for optional profile checksum.
	 *
	 * @var string
	 */
	const REDDY_PROFILE_HASH_META_KEY = '_mksddn_reddy_profile_hash';

	/**
	 * Resolve existing user by Reddy ID or create user on first login.
	 *
	 * @param string $reddy_id Reddy identifier.
	 * @param array  $profile_data Optional profile payload from upstream.
	 * @return WP_User|WP_Error
	 */
	public function resolve_or_create_user( $reddy_id, array $profile_data = array() ) {
		$reddy_id = sanitize_text_field( (string) $reddy_id );

		if ( '' === $reddy_id ) {
			return new WP_Error( 'invalid_identity', __( 'Unable to resolve identity.', 'mksddn-reddy-auth' ) );
		}

		$user = $this->get_user_by_reddy_id( $reddy_id );
		if ( $user instanceof WP_User ) {
			$this->store_profile_hash( $user->ID, $profile_data );

			return $user;
		}

		return $this->create_user( $reddy_id, $profile_data );
	}

	/**
	 * Get user by mapped Reddy ID.
	 *
	 * @param string $reddy_id Reddy identifier.
	 * @return WP_User|null
	 */
	public function get_user_by_reddy_id( $reddy_id ) {
		$reddy_id = sanitize_text_field( (string) $reddy_id );
		if ( '' === $reddy_id ) {
			return null;
		}

		$users = get_users(
			array(
				'number' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- required lookup by Reddy ID mapping.
				'meta_key' => self::REDDY_ID_META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- required lookup by Reddy ID mapping.
				'meta_value' => $reddy_id,
			)
		);

		if ( empty( $users ) || ! isset( $users[0] ) || ! ( $users[0] instanceof WP_User ) ) {
			return null;
		}

		return $users[0];
	}

	/**
	 * Create a new WordPress user mapped to provided Reddy ID.
	 *
	 * @param string $reddy_id Reddy identifier.
	 * @param array  $profile_data Optional profile payload.
	 * @return WP_User|WP_Error
	 */
	private function create_user( $reddy_id, array $profile_data ) {
		$user_data = $this->build_user_data( $reddy_id );

		/**
		 * Allow custom mapping before user creation.
		 */
		$user_data = apply_filters( 'mksddn_reddy_map_user_data', $user_data, $reddy_id, $profile_data );

		$user_id = wp_insert_user( $user_data );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'identity_create_failed', __( 'Unable to resolve identity.', 'mksddn-reddy-auth' ) );
		}

		update_user_meta( $user_id, self::REDDY_ID_META_KEY, $reddy_id );
		$this->store_profile_hash( $user_id, $profile_data );

		$user = get_user_by( 'id', $user_id );
		if ( ! ( $user instanceof WP_User ) ) {
			return new WP_Error( 'identity_create_failed', __( 'Unable to resolve identity.', 'mksddn-reddy-auth' ) );
		}

		return $user;
	}

	/**
	 * Build default user payload for wp_insert_user.
	 *
	 * @param string $reddy_id Reddy identifier.
	 * @return array<string, mixed>
	 */
	private function build_user_data( $reddy_id ) {
		$sanitized_id = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $reddy_id );
		$sanitized_id = '' !== $sanitized_id ? strtolower( $sanitized_id ) : md5( $reddy_id );
		$base_login   = sanitize_user( 'reddy_' . $sanitized_id, true );
		$user_login   = $this->next_available_login( $base_login );
		$user_email   = $this->next_available_email( 'reddy-' . $sanitized_id . '@invalid.local' );

		return array(
			'user_login' => $user_login,
			'user_pass'  => wp_generate_password( 32, true, true ),
			'user_email' => $user_email,
			'role'       => get_option( 'default_role', 'subscriber' ),
		);
	}

	/**
	 * Return unique user_login.
	 *
	 * @param string $base_login Base login value.
	 * @return string
	 */
	private function next_available_login( $base_login ) {
		$base_login = '' !== $base_login ? $base_login : 'reddy_user';
		$candidate  = $base_login;
		$index      = 1;

		while ( username_exists( $candidate ) ) {
			$candidate = $base_login . '_' . $index;
			++$index;
		}

		return $candidate;
	}

	/**
	 * Return unique synthetic email for service-created users.
	 *
	 * @param string $base_email Base email value.
	 * @return string
	 */
	private function next_available_email( $base_email ) {
		$base_email = sanitize_email( $base_email );
		if ( '' === $base_email ) {
			$base_email = 'reddy-user@invalid.local';
		}

		list( $local, $domain ) = explode( '@', $base_email );
		$candidate              = $base_email;
		$index                  = 1;

		while ( email_exists( $candidate ) ) {
			$candidate = $local . '+' . $index . '@' . $domain;
			++$index;
		}

		return $candidate;
	}

	/**
	 * Persist profile hash for quick change detection.
	 *
	 * @param int   $user_id User ID.
	 * @param array $profile_data Optional profile payload.
	 * @return void
	 */
	private function store_profile_hash( $user_id, array $profile_data ) {
		if ( empty( $profile_data ) ) {
			return;
		}

		$profile_hash = hash_hmac( 'sha256', wp_json_encode( $profile_data ), wp_salt( 'auth' ) );
		update_user_meta( $user_id, self::REDDY_PROFILE_HASH_META_KEY, $profile_hash );
	}
}
