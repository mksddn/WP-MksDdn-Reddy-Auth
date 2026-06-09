<?php
/**
 * Cross-device login intent state for one-click approval polling.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Login_Intent_Service {
	/**
	 * Poll rate limit max requests per window.
	 *
	 * @var int
	 */
	private $poll_limit = 60;

	/**
	 * Poll rate limit window in seconds.
	 *
	 * @var int
	 */
	private $poll_window_seconds = 600;

	/**
	 * Complete-intent rate limit max requests per window.
	 *
	 * @var int
	 */
	private $consume_limit = 20;

	/**
	 * Complete-intent rate limit window in seconds.
	 *
	 * @var int
	 */
	private $consume_window_seconds = 600;

	/**
	 * Intent status: waiting for messenger approval.
	 *
	 * @var string
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Intent status: approved via magic link.
	 *
	 * @var string
	 */
	const STATUS_APPROVED = 'approved';

	/**
	 * Intent status: consumed after browser login completed.
	 *
	 * @var string
	 */
	const STATUS_CONSUMED = 'consumed';

	/**
	 * Intent TTL in seconds.
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
	 * Create a pending login intent for a Reddy user.
	 *
	 * @param string $reddy_id Reddy user identifier.
	 * @return array{id: string, secret: string}|WP_Error
	 */
	public function create( $reddy_id ) {
		$reddy_id = sanitize_text_field( (string) $reddy_id );
		if ( '' === $reddy_id ) {
			return new WP_Error( 'invalid_request', __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ) );
		}

		$intent_id     = wp_generate_password( 32, false, false );
		$intent_secret = wp_generate_password( 32, false, false );
		$stored        = set_transient(
			$this->get_storage_key( $intent_id ),
			array(
				'reddy_id'    => $reddy_id,
				'secret_hash' => $this->hash_secret( $intent_secret ),
				'status'      => self::STATUS_PENDING,
				'created_at'  => time(),
				'expires_at'  => time() + $this->ttl_seconds,
			),
			$this->ttl_seconds
		);

		if ( ! $stored ) {
			return new WP_Error( 'intent_storage_failed', __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ) );
		}

		return array(
			'id'     => $intent_id,
			'secret' => $intent_secret,
		);
	}

	/**
	 * Return intent status for polling clients.
	 *
	 * @param string $intent_id     Intent identifier.
	 * @param string $intent_secret Intent secret from create().
	 * @return array{status: string, reddy_id: string}|WP_Error
	 */
	public function get_status( $intent_id, $intent_secret ) {
		$intent_id = sanitize_text_field( (string) $intent_id );
		$limit     = $this->assert_rate_limit( 'poll', $intent_id, $this->get_request_ip(), $this->poll_limit, $this->poll_window_seconds );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$state = $this->get_validated_state( $intent_id, $intent_secret );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return array(
			'status'   => (string) $state['status'],
			'reddy_id' => sanitize_text_field( (string) $state['reddy_id'] ),
		);
	}

	/**
	 * Mark intent as approved after magic link click.
	 *
	 * @param string $intent_id     Intent identifier.
	 * @param string $intent_secret Optional intent secret for strict approval checks.
	 * @param string $reddy_id      Optional Reddy ID asserted by webhook payload.
	 * @return true|WP_Error
	 */
	public function approve( $intent_id, $intent_secret = '', $reddy_id = '' ) {
		$intent_id     = sanitize_text_field( (string) $intent_id );
		$intent_secret = sanitize_text_field( (string) $intent_secret );
		$reddy_id      = sanitize_text_field( (string) $reddy_id );
		$state     = get_transient( $this->get_storage_key( $intent_id ) );

		if ( ! is_array( $state ) || empty( $state['reddy_id'] ) ) {
			return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		if ( time() > (int) $state['expires_at'] ) {
			delete_transient( $this->get_storage_key( $intent_id ) );

			return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		if ( self::STATUS_CONSUMED === (string) $state['status'] ) {
			return new WP_Error( 'intent_consumed', __( 'Authorization request was already completed.', 'mksddn-reddy-auth' ) );
		}

		if ( '' !== $intent_secret ) {
			if ( ! hash_equals( (string) $state['secret_hash'], $this->hash_secret( $intent_secret ) ) ) {
				return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
			}
		}

		if ( '' !== $reddy_id && ! hash_equals( sanitize_text_field( (string) $state['reddy_id'] ), $reddy_id ) ) {
			return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		$state['status'] = self::STATUS_APPROVED;
		set_transient( $this->get_storage_key( $intent_id ), $state, max( 1, (int) $state['expires_at'] - time() ) );

		return true;
	}

	/**
	 * Consume an approved intent and return the Reddy user identifier.
	 *
	 * @param string $intent_id     Intent identifier.
	 * @param string $intent_secret Intent secret from create().
	 * @return string|WP_Error
	 */
	public function consume_approved( $intent_id, $intent_secret ) {
		$intent_id = sanitize_text_field( (string) $intent_id );
		$limit     = $this->assert_rate_limit( 'consume', $intent_id, $this->get_request_ip(), $this->consume_limit, $this->consume_window_seconds );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		$state = $this->get_validated_state( $intent_id, $intent_secret );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( self::STATUS_APPROVED !== (string) $state['status'] ) {
			return new WP_Error( 'intent_not_approved', __( 'Authorization is not confirmed yet.', 'mksddn-reddy-auth' ) );
		}

		delete_transient( $this->get_storage_key( $intent_id ) );

		return sanitize_text_field( (string) $state['reddy_id'] );
	}

	/**
	 * Validate intent credentials and return stored state.
	 *
	 * @param string $intent_id     Intent identifier.
	 * @param string $intent_secret Intent secret from create().
	 * @return array<string, mixed>|WP_Error
	 */
	private function get_validated_state( $intent_id, $intent_secret ) {
		$intent_id     = sanitize_text_field( (string) $intent_id );
		$intent_secret = sanitize_text_field( (string) $intent_secret );

		if ( '' === $intent_id || '' === $intent_secret ) {
			return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		$state = get_transient( $this->get_storage_key( $intent_id ) );
		if ( ! is_array( $state ) || empty( $state['secret_hash'] ) || empty( $state['reddy_id'] ) || empty( $state['status'] ) ) {
			return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		if ( time() > (int) $state['expires_at'] ) {
			delete_transient( $this->get_storage_key( $intent_id ) );

			return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		if ( ! hash_equals( (string) $state['secret_hash'], $this->hash_secret( $intent_secret ) ) ) {
			return new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
		}

		return $state;
	}

	/**
	 * Build transient storage key.
	 *
	 * @param string $intent_id Intent identifier.
	 * @return string
	 */
	private function get_storage_key( $intent_id ) {
		return 'mksddn_reddy_intent_' . md5( (string) $intent_id );
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
	 * Enforce request limits for intent polling and completion.
	 *
	 * @param string $action Action key.
	 * @param string $intent_id Intent identifier.
	 * @param string $ip Client IP.
	 * @param int    $limit Allowed requests per window.
	 * @param int    $window_seconds Window size in seconds.
	 * @return true|WP_Error
	 */
	private function assert_rate_limit( $action, $intent_id, $ip, $limit, $window_seconds ) {
		if ( '' === $intent_id ) {
			return true;
		}

		$identity_hash = md5( $action . '|' . $intent_id . '|' . $ip );
		$count_key     = 'mksddn_reddy_intent_rate_count_' . $identity_hash;
		$blocked_key   = 'mksddn_reddy_intent_rate_block_' . $identity_hash;
		$current_time  = time();
		$blocked_until = (int) get_transient( $blocked_key );

		if ( $blocked_until > $current_time ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Try again later.', 'mksddn-reddy-auth' ) );
		}

		$count = (int) get_transient( $count_key );
		if ( $count >= $limit ) {
			set_transient( $blocked_key, $current_time + 300, 300 );

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
		$settings = Mksddn_Reddy_Auth_Settings_Page::get_runtime_settings();

		if ( isset( $settings['magic_link_ttl_seconds'] ) ) {
			$this->ttl_seconds = max( 60, min( 900, (int) $settings['magic_link_ttl_seconds'] ) );
		} elseif ( isset( $settings['otp_ttl_seconds'] ) ) {
			$this->ttl_seconds = max( 60, min( 900, (int) $settings['otp_ttl_seconds'] ) );
		}
	}
}
