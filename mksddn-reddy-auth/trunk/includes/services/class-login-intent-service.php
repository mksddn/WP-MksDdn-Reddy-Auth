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
	 * @param string $intent_id Intent identifier.
	 * @return true|WP_Error
	 */
	public function approve( $intent_id ) {
		$intent_id = sanitize_text_field( (string) $intent_id );
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
	 * Load configurable values from settings.
	 *
	 * @return void
	 */
	private function bootstrap_from_settings() {
		$settings = get_option( Mksddn_Reddy_Auth_Settings_Page::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( isset( $settings['magic_link_ttl_seconds'] ) ) {
			$this->ttl_seconds = max( 60, min( 900, (int) $settings['magic_link_ttl_seconds'] ) );
		} elseif ( isset( $settings['otp_ttl_seconds'] ) ) {
			$this->ttl_seconds = max( 60, min( 900, (int) $settings['otp_ttl_seconds'] ) );
		}
	}
}
