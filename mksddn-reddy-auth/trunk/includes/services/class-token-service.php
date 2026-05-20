<?php
/**
 * Opaque bearer token service.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Token_Service {
	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'mksddn_reddy_auth_settings';

	/**
	 * Default token TTL (30 days).
	 *
	 * @var int
	 */
	private $ttl_seconds = 2592000;

	/**
	 * Token repository.
	 *
	 * @var Mksddn_Reddy_Auth_Token_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Token_Repository $repository Repository instance.
	 */
	public function __construct( Mksddn_Reddy_Auth_Token_Repository $repository ) {
		$this->repository = $repository;
		$this->bootstrap_from_settings();
	}

	/**
	 * Issue access token for user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function issue_token( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', __( 'Unable to issue token.', 'mksddn-reddy-auth' ) );
		}

		try {
			$raw_token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return new WP_Error( 'token_generation_failed', __( 'Unable to issue token.', 'mksddn-reddy-auth' ) );
		}

		$created_at = gmdate( 'Y-m-d H:i:s' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $this->ttl_seconds );
		$token_hash = $this->hash_token( $raw_token );
		$stored     = $this->repository->insert(
			array(
				'user_id'    => $user_id,
				'token_hash' => $token_hash,
				'expires_at' => $expires_at,
				'created_at' => $created_at,
				'ip_hash'    => $this->hash_token( $this->get_request_ip() ),
				'ua_hash'    => $this->hash_token( $this->get_request_user_agent() ),
			)
		);

		if ( ! $stored ) {
			return new WP_Error( 'token_storage_failed', __( 'Unable to issue token.', 'mksddn-reddy-auth' ) );
		}

		return array(
			'access_token' => $raw_token,
			'expires_at'   => $expires_at,
			'token_type'   => 'Bearer',
		);
	}

	/**
	 * Validate raw bearer token and return user.
	 *
	 * @param string $raw_token Raw bearer token.
	 * @return WP_User|WP_Error
	 */
	public function validate_token( $raw_token ) {
		$raw_token = sanitize_text_field( (string) $raw_token );
		if ( '' === $raw_token ) {
			return new WP_Error( 'invalid_token', __( 'Invalid token.', 'mksddn-reddy-auth' ) );
		}

		$token_hash = $this->hash_token( $raw_token );
		$record     = $this->repository->find_active_by_hash( $token_hash );
		if ( ! $record || empty( $record->user_id ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid token.', 'mksddn-reddy-auth' ) );
		}

		$user = get_user_by( 'id', (int) $record->user_id );
		if ( ! ( $user instanceof WP_User ) ) {
			return new WP_Error( 'invalid_token', __( 'Invalid token.', 'mksddn-reddy-auth' ) );
		}

		$this->repository->touch_last_used( $token_hash );

		return $user;
	}

	/**
	 * Revoke raw token if exists.
	 *
	 * @param string $raw_token Raw bearer token.
	 * @return void
	 */
	public function revoke_token( $raw_token ) {
		$raw_token = sanitize_text_field( (string) $raw_token );
		if ( '' === $raw_token ) {
			return;
		}

		$this->repository->revoke_by_hash( $this->hash_token( $raw_token ) );
	}

	/**
	 * Extract bearer token from request headers.
	 *
	 * @return string
	 */
	public function get_bearer_token_from_request() {
		$header = '';

		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( '' === $header || 1 !== preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return '';
		}

		return sanitize_text_field( trim( (string) $matches[1] ) );
	}

	/**
	 * Hash token-like value.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private function hash_token( $raw ) {
		return hash_hmac( 'sha256', (string) $raw, wp_salt( 'auth' ) );
	}

	/**
	 * Return request IP.
	 *
	 * @return string
	 */
	private function get_request_ip() {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '0.0.0.0';
	}

	/**
	 * Return request user agent.
	 *
	 * @return string
	 */
	private function get_request_user_agent() {
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		return 'unknown';
	}

	/**
	 * Load configurable values from settings.
	 *
	 * @return void
	 */
	private function bootstrap_from_settings() {
		$settings = get_option( self::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( isset( $settings['token_ttl_seconds'] ) ) {
			$this->ttl_seconds = max( 3600, min( 7776000, (int) $settings['token_ttl_seconds'] ) );
		}
	}
}
