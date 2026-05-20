<?php
/**
 * Reddy API client abstraction.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Reddy_Client {
	/**
	 * Reddy bot API domain.
	 *
	 * @var string
	 */
	const API_DOMAIN = 'https://bot.reddy.team';

	/**
	 * Reddy bot API version prefix.
	 *
	 * @var string
	 */
	const API_VERSION = '/v2';

	/**
	 * Send endpoint path.
	 *
	 * @var string
	 */
	const SEND_ENDPOINT = '/send';

	/**
	 * Development fallback option key for bot token.
	 *
	 * @var string
	 */
	const BOT_TOKEN_OPTION_KEY = 'mksddn_reddy_auth_bot_token';

	/**
	 * Request OTP delivery from Reddy API.
	 *
	 * @param string $reddy_id Reddy user identifier.
	 * @param string $otp_code One-time password.
	 * @param int    $ttl_seconds OTP lifetime.
	 * @return true|WP_Error
	 */
	public function send_otp_code( $reddy_id, $otp_code, $ttl_seconds ) {
		$reddy_id = sanitize_text_field( $reddy_id );
		$otp_code = sanitize_text_field( $otp_code );
		$bot_token = $this->get_bot_token();

		if ( '' === $reddy_id || '' === $otp_code || $ttl_seconds <= 0 ) {
			return new WP_Error( 'invalid_reddy_id', __( 'Invalid Reddy ID.', 'mksddn-reddy-auth' ) );
		}

		if ( '' === $bot_token ) {
			return new WP_Error( 'bot_token_missing', __( 'Bot token is not configured.', 'mksddn-reddy-auth' ) );
		}

		/**
		 * Placeholder hook before sending code to upstream API.
		 */
		do_action( 'mksddn_reddy_before_send_code', $reddy_id );

		/**
		 * Placeholder hook for transport implementation.
		 * OTP value is intentionally not passed to avoid accidental leaks.
		 */
		$transport_result = apply_filters( 'mksddn_reddy_send_code_transport', null, $reddy_id, (int) $ttl_seconds, $otp_code );
		if ( null === $transport_result ) {
			$transport_result = $this->send_via_default_transport( $bot_token, $reddy_id, $otp_code, $ttl_seconds );
		}

		if ( true !== $transport_result ) {
			if ( is_wp_error( $transport_result ) ) {
				return $transport_result;
			}

			return new WP_Error( 'transport_delivery_failed', __( 'Bot did not confirm OTP delivery.', 'mksddn-reddy-auth' ) );
		}

		do_action( 'mksddn_reddy_after_send_code', $reddy_id, (int) $ttl_seconds );

		return true;
	}

	/**
	 * Send test message to verify bot connection.
	 *
	 * @param string $reddy_id Target Reddy user ID.
	 * @return true|WP_Error
	 */
	public function test_connection( $reddy_id ) {
		$reddy_id = sanitize_text_field( (string) $reddy_id );
		$bot_token = $this->get_bot_token();

		if ( '' === $reddy_id ) {
			return new WP_Error( 'test_reddy_id_missing', __( 'Test Reddy ID is required.', 'mksddn-reddy-auth' ) );
		}

		if ( '' === $bot_token ) {
			return new WP_Error( 'bot_token_missing', __( 'Bot token is not configured.', 'mksddn-reddy-auth' ) );
		}

		$url      = self::API_DOMAIN . self::API_VERSION . $bot_token . self::SEND_ENDPOINT;
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'msg'     => __( 'Reddy bot connection test from WordPress plugin.', 'mksddn-reddy-auth' ),
						'userKey' => $reddy_id,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body_raw    = (string) wp_remote_retrieve_body( $response );
		$body_json   = json_decode( $body_raw, true );

		if ( $status_code >= 200 && $status_code < 300 ) {
			return true;
		}

		$error_message = __( 'Bot API request failed.', 'mksddn-reddy-auth' );
		if ( is_array( $body_json ) && ! empty( $body_json['message'] ) ) {
			$error_message = sanitize_text_field( (string) $body_json['message'] );
		}

		return new WP_Error( 'bot_test_failed', $error_message );
	}

	/**
	 * Default transport implementation for Reddy bot API.
	 *
	 * @param string $bot_token Bot token.
	 * @param string $reddy_id Reddy user ID.
	 * @param string $otp_code One-time code.
	 * @param int    $ttl_seconds Code TTL.
	 * @return true|WP_Error
	 */
	private function send_via_default_transport( $bot_token, $reddy_id, $otp_code, $ttl_seconds ) {
		$message = apply_filters(
			'mksddn_reddy_otp_message',
			sprintf(
				/* translators: 1: otp code 2: ttl seconds */
				__( 'Your verification code: %1$s. It expires in %2$d seconds.', 'mksddn-reddy-auth' ),
				$otp_code,
				(int) $ttl_seconds
			),
			$reddy_id,
			(int) $ttl_seconds
		);

		$url     = self::API_DOMAIN . self::API_VERSION . $bot_token . self::SEND_ENDPOINT;
		$payload = array(
			'msg'     => (string) $message,
			'userKey' => $reddy_id,
		);

		$last_error = null;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 8,
					'headers' => array(
						'Content-Type' => 'application/json; charset=utf-8',
					),
					'body'    => wp_json_encode( $payload ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error = $response;
				continue;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$body_raw    = (string) wp_remote_retrieve_body( $response );
			$body_json   = json_decode( $body_raw, true );

			if ( $status_code >= 200 && $status_code < 300 ) {
				return true;
			}

			$error_message = __( 'Bot API rejected OTP delivery.', 'mksddn-reddy-auth' );
			if ( is_array( $body_json ) && ! empty( $body_json['message'] ) ) {
				$error_message = sanitize_text_field( (string) $body_json['message'] );
			}

			$last_error = new WP_Error(
				'reddy_api_http_error',
				$error_message,
				array(
					'status_code' => $status_code,
				)
			);
		}

		if ( is_wp_error( $last_error ) ) {
			return $last_error;
		}

		return new WP_Error( 'reddy_transport_failed', __( 'Unable to deliver OTP via bot API.', 'mksddn-reddy-auth' ) );
	}

	/**
	 * Resolve bot token from constant or option.
	 *
	 * @return string
	 */
	private function get_bot_token() {
		if ( defined( 'MKSDDN_REDDY_BOT_TOKEN' ) && '' !== (string) MKSDDN_REDDY_BOT_TOKEN ) {
			return (string) MKSDDN_REDDY_BOT_TOKEN;
		}

		return (string) get_option( self::BOT_TOKEN_OPTION_KEY, '' );
	}
}
