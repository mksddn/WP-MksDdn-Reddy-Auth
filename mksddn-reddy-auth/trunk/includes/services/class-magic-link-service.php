<?php
/**
 * One-time signed magic link tokens for one-click auth.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Magic_Link_Service {
	/**
	 * Verify rate limit max attempts per window.
	 *
	 * @var int
	 */
	private $verify_limit = 10;

	/**
	 * Verify rate window in seconds.
	 *
	 * @var int
	 */
	private $verify_window_seconds = 600;

	/**
	 * Magic link TTL in seconds.
	 *
	 * @var int
	 */
	private $ttl_seconds = 300;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->bootstrap_from_settings();
	}

	/**
	 * Issue a one-time magic link token for a Reddy user and login intent.
	 *
	 * @param string $reddy_id  Reddy user identifier.
	 * @param string $intent_id Login intent identifier.
	 * @return array{token: string, url: string}|WP_Error
	 */
	public function issue( $reddy_id, $intent_id ) {
		$reddy_id  = sanitize_text_field( (string) $reddy_id );
		$intent_id = sanitize_text_field( (string) $intent_id );

		if ( '' === $reddy_id || '' === $intent_id ) {
			return new WP_Error( 'invalid_request', __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ) );
		}

		$token = wp_generate_password( 48, false, false );
		$stored = set_transient(
			$this->get_storage_key( $token ),
			array(
				'token_hash' => $this->hash_secret( $token ),
				'reddy_id'   => $reddy_id,
				'intent_id'  => $intent_id,
				'expires_at' => time() + $this->ttl_seconds,
			),
			$this->ttl_seconds
		);

		if ( ! $stored ) {
			return new WP_Error( 'magic_link_storage_failed', __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ) );
		}

		$url = add_query_arg(
			array(
				'action' => 'mksddn_reddy_verify_link',
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);

		/**
		 * Filter magic link URL before delivery.
		 *
		 * @param string $url       Magic link URL.
		 * @param string $reddy_id  Reddy user identifier.
		 * @param string $intent_id Login intent identifier.
		 */
		$url = (string) apply_filters( 'mksddn_reddy_magic_link_url', $url, $reddy_id, $intent_id );

		return array(
			'token' => $token,
			'url'   => $url,
		);
	}

	/**
	 * Verify and consume a magic link token.
	 *
	 * @param string $token Raw token from request.
	 * @return array{reddy_id: string, intent_id: string}|WP_Error
	 */
	public function verify_and_consume( $token ) {
		$token = sanitize_text_field( (string) $token );

		if ( strlen( $token ) < 32 ) {
			return new WP_Error( 'invalid_magic_link', __( 'Authorization link is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		$ip                = $this->get_request_ip();
		$rate_limit_result = $this->assert_rate_limit( $ip );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		$state = get_transient( $this->get_storage_key( $token ) );
		if ( ! is_array( $state ) || empty( $state['token_hash'] ) || empty( $state['reddy_id'] ) || empty( $state['intent_id'] ) ) {
			return new WP_Error( 'invalid_magic_link', __( 'Authorization link is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		if ( time() > (int) $state['expires_at'] ) {
			delete_transient( $this->get_storage_key( $token ) );

			return new WP_Error( 'invalid_magic_link', __( 'Authorization link is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		if ( ! hash_equals( (string) $state['token_hash'], $this->hash_secret( $token ) ) ) {
			return new WP_Error( 'invalid_magic_link', __( 'Authorization link is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		delete_transient( $this->get_storage_key( $token ) );

		return array(
			'reddy_id'  => sanitize_text_field( (string) $state['reddy_id'] ),
			'intent_id' => sanitize_text_field( (string) $state['intent_id'] ),
		);
	}

	/**
	 * Return configured TTL.
	 *
	 * @return int
	 */
	public function get_ttl_seconds() {
		return (int) $this->ttl_seconds;
	}

	/**
	 * Build transient storage key.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private function get_storage_key( $token ) {
		return 'mksddn_reddy_magic_' . md5( (string) $token );
	}

	/**
	 * Hash sensitive values before persistence.
	 *
	 * @param string $value Value to hash.
	 * @return string
	 */
	private function hash_secret( $value ) {
		return hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) );
	}

	/**
	 * Return request IP address.
	 *
	 * @return string
	 */
	private function get_request_ip() {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $remote_addr ) {
			return $remote_addr;
		}

		return '0.0.0.0';
	}

	/**
	 * Enforce verify rate limits.
	 *
	 * @param string $ip Client IP.
	 * @return true|WP_Error
	 */
	private function assert_rate_limit( $ip ) {
		$identity_hash = md5( 'magic_verify|' . $ip );
		$count_key     = 'mksddn_reddy_magic_rate_count_' . $identity_hash;
		$blocked_key   = 'mksddn_reddy_magic_rate_block_' . $identity_hash;
		$current_time  = time();
		$blocked_until = (int) get_transient( $blocked_key );

		if ( $blocked_until > $current_time ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again later.', 'mksddn-reddy-auth' ) );
		}

		$count = (int) get_transient( $count_key );
		if ( $count >= $this->verify_limit ) {
			set_transient( $blocked_key, $current_time + 300, 300 );

			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again later.', 'mksddn-reddy-auth' ) );
		}

		set_transient( $count_key, $count + 1, $this->verify_window_seconds );

		return true;
	}

	/**
	 * Load configurable values from settings.
	 *
	 * @return void
	 */
	private function bootstrap_from_settings() {
		$settings = Mksddn_Reddy_Auth_Settings_Page::get_runtime_settings();

		if ( isset( $settings['magic_link_ttl_seconds'] ) ) {
			$this->ttl_seconds = max( 60, min( 900, (int) $settings['magic_link_ttl_seconds'] ) );
		} elseif ( isset( $settings['otp_ttl_seconds'] ) ) {
			$this->ttl_seconds = max( 60, min( 900, (int) $settings['otp_ttl_seconds'] ) );
		}
	}
}
