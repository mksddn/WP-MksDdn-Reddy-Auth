<?php
/**
 * OTP service scaffold with defaults.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Otp_Service {
	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'mksddn_reddy_auth_settings';

	/**
	 * Send-code rate limit max requests per window.
	 *
	 * @var int
	 */
	private $send_limit = 5;

	/**
	 * Login rate limit max attempts per window.
	 *
	 * @var int
	 */
	private $login_limit = 7;

	/**
	 * Send-code rate window in seconds.
	 *
	 * @var int
	 */
	private $send_window_seconds = 300;

	/**
	 * Login rate window in seconds.
	 *
	 * @var int
	 */
	private $login_window_seconds = 600;

	/**
	 * Reddy client.
	 *
	 * @var Mksddn_Reddy_Auth_Reddy_Client
	 */
	private $reddy_client;

	/**
	 * OTP TTL in seconds.
	 *
	 * @var int
	 */
	private $ttl_seconds = 300;

	/**
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Reddy_Client $reddy_client Reddy API client.
	 */
	public function __construct( Mksddn_Reddy_Auth_Reddy_Client $reddy_client ) {
		$this->reddy_client = $reddy_client;
		$this->bootstrap_from_settings();
	}

	/**
	 * Request OTP code dispatch for given Reddy ID.
	 *
	 * @param string $reddy_id Reddy user identifier.
	 * @return true|WP_Error
	 */
	public function request_code( $reddy_id ) {
		$reddy_id = $this->normalize_reddy_id( $reddy_id );

		if ( '' === $reddy_id ) {
			return new WP_Error( 'invalid_request', __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ) );
		}

		$ip = $this->get_request_ip();
		$rate_limit_result = $this->assert_rate_limit( 'send', $reddy_id, $ip, $this->send_limit, $this->send_window_seconds );

		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		try {
			$code = (string) random_int( 100000, 999999 );
		} catch ( Exception $exception ) {
			return new WP_Error( 'otp_generation_failed', __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ) );
		}

		$code_hash = $this->hash_secret( $code );
		$stored    = set_transient(
			$this->get_otp_key( $reddy_id ),
			array(
				'code_hash'   => $code_hash,
				'created_at'  => time(),
				'expires_at'  => time() + $this->ttl_seconds,
				'request_ip'  => $this->hash_secret( $ip ),
				'request_ua'  => $this->hash_secret( $this->get_request_user_agent() ),
			),
			$this->ttl_seconds
		);

		if ( ! $stored ) {
			return new WP_Error( 'otp_storage_failed', __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ) );
		}

		$send_result = $this->reddy_client->send_otp_code( $reddy_id, $code, $this->ttl_seconds );

		if ( is_wp_error( $send_result ) ) {
			delete_transient( $this->get_otp_key( $reddy_id ) );

			return $send_result;
		}

		return true;
	}

	/**
	 * Verify one-time code for provided Reddy ID.
	 *
	 * @param string $reddy_id Reddy user identifier.
	 * @param string $code One-time password.
	 * @return true|WP_Error
	 */
	public function verify_code( $reddy_id, $code ) {
		$reddy_id = $this->normalize_reddy_id( $reddy_id );
		$code     = sanitize_text_field( (string) $code );

		if ( '' === $reddy_id || ! preg_match( '/^\d{6}$/', $code ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'mksddn-reddy-auth' ) );
		}

		$ip = $this->get_request_ip();
		$rate_limit_result = $this->assert_rate_limit( 'login', $reddy_id, $ip, $this->login_limit, $this->login_window_seconds );

		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		$otp_state = get_transient( $this->get_otp_key( $reddy_id ) );

		if ( ! is_array( $otp_state ) || empty( $otp_state['code_hash'] ) || empty( $otp_state['expires_at'] ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'mksddn-reddy-auth' ) );
		}

		if ( time() > (int) $otp_state['expires_at'] ) {
			delete_transient( $this->get_otp_key( $reddy_id ) );

			return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'mksddn-reddy-auth' ) );
		}

		$input_hash = $this->hash_secret( $code );

		if ( ! hash_equals( (string) $otp_state['code_hash'], $input_hash ) ) {
			return new WP_Error( 'invalid_credentials', __( 'Invalid credentials.', 'mksddn-reddy-auth' ) );
		}

		// One-time code: remove immediately after successful verification.
		delete_transient( $this->get_otp_key( $reddy_id ) );

		return true;
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
	 * Build transient key for OTP state.
	 *
	 * @param string $reddy_id Reddy user identifier.
	 * @return string
	 */
	private function get_otp_key( $reddy_id ) {
		return 'mksddn_reddy_otp_' . md5( $reddy_id );
	}

	/**
	 * Normalize Reddy ID from input.
	 *
	 * @param string $reddy_id Input value.
	 * @return string
	 */
	private function normalize_reddy_id( $reddy_id ) {
		return sanitize_text_field( (string) $reddy_id );
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
		$keys = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

				if ( 'HTTP_X_FORWARDED_FOR' === $key ) {
					$parts = explode( ',', $value );
					$value = trim( (string) $parts[0] );
				}

				return $value;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Return request user agent.
	 *
	 * @return string
	 */
	private function get_request_user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return 'unknown';
		}

		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	}

	/**
	 * Enforce per-action rate limits with progressive temporary blocks.
	 *
	 * @param string $action Action key.
	 * @param string $reddy_id Reddy user identifier.
	 * @param string $ip Client IP.
	 * @param int    $limit Allowed requests per window.
	 * @param int    $window_seconds Window duration.
	 * @return true|WP_Error
	 */
	private function assert_rate_limit( $action, $reddy_id, $ip, $limit, $window_seconds ) {
		$identity_hash   = md5( $action . '|' . $reddy_id . '|' . $ip );
		$count_key       = 'mksddn_reddy_rate_count_' . $identity_hash;
		$blocked_key     = 'mksddn_reddy_rate_block_' . $identity_hash;
		$violations_key  = 'mksddn_reddy_rate_violation_' . $identity_hash;
		$current_time    = time();
		$blocked_until   = (int) get_transient( $blocked_key );

		if ( $blocked_until > $current_time ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again later.', 'mksddn-reddy-auth' ) );
		}

		$count = (int) get_transient( $count_key );

		if ( $count >= $limit ) {
			$violations      = (int) get_transient( $violations_key ) + 1;
			$backoff_seconds = min( 900, 30 * ( 2 ** max( 0, $violations - 1 ) ) );

			set_transient( $violations_key, $violations, DAY_IN_SECONDS );
			set_transient( $blocked_key, $current_time + $backoff_seconds, $backoff_seconds );

			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again later.', 'mksddn-reddy-auth' ) );
		}

		set_transient( $count_key, $count + 1, $window_seconds );

		return true;
	}

	/**
	 * Load configurable values from settings.
	 *
	 * @return void
	 */
	private function bootstrap_from_settings() {
		$settings                 = get_option( self::SETTINGS_OPTION_KEY, array() );
		$settings                 = is_array( $settings ) ? $settings : array();
		$this->ttl_seconds        = isset( $settings['otp_ttl_seconds'] ) ? max( 60, min( 900, (int) $settings['otp_ttl_seconds'] ) ) : $this->ttl_seconds;
		$this->send_limit         = isset( $settings['send_rate_limit'] ) ? max( 1, min( 20, (int) $settings['send_rate_limit'] ) ) : $this->send_limit;
		$this->login_limit        = isset( $settings['login_rate_limit'] ) ? max( 1, min( 30, (int) $settings['login_rate_limit'] ) ) : $this->login_limit;
	}
}
