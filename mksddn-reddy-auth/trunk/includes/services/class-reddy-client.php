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
	 * @param string               $reddy_id Reddy user identifier.
	 * @param string               $otp_code One-time password.
	 * @param int                  $ttl_seconds OTP lifetime.
	 * @param array<string, mixed> $delivery Delivery options.
	 * @return true|WP_Error
	 */
	public function send_otp_code( $reddy_id, $otp_code, $ttl_seconds, array $delivery = array() ) {
		$reddy_id  = sanitize_text_field( $reddy_id );
		$otp_code  = sanitize_text_field( $otp_code );
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
		 * OTP value is passed as the 4th filter argument; keep handlers trusted.
		 */
		$transport_result = apply_filters( 'mksddn_reddy_send_code_transport', null, $reddy_id, (int) $ttl_seconds, $otp_code );
		if ( null === $transport_result ) {
			$transport_result = $this->send_via_default_transport( $bot_token, $reddy_id, $otp_code, $ttl_seconds, $delivery );
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
		$reddy_id  = sanitize_text_field( (string) $reddy_id );
		$bot_token = $this->get_bot_token();

		if ( '' === $reddy_id ) {
			return new WP_Error( 'test_reddy_id_missing', __( 'Test Reddy ID is required.', 'mksddn-reddy-auth' ) );
		}

		if ( '' === $bot_token ) {
			return new WP_Error( 'bot_token_missing', __( 'Bot token is not configured.', 'mksddn-reddy-auth' ) );
		}

		$url      = self::API_DOMAIN . self::API_VERSION . $bot_token . self::SEND_ENDPOINT;
		$message  = apply_filters(
			'mksddn_reddy_bot_test_message',
			$this->resolve_bot_test_message(),
			$reddy_id
		);
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'msg'     => (string) $message,
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
	 * @param string               $bot_token Bot token.
	 * @param string               $reddy_id Reddy user ID.
	 * @param string               $otp_code One-time code.
	 * @param int                  $ttl_seconds Code TTL.
	 * @param array<string, mixed> $delivery Delivery options.
	 * @return true|WP_Error
	 */
	private function send_via_default_transport( $bot_token, $reddy_id, $otp_code, $ttl_seconds, array $delivery = array() ) {
		$delivery_mode  = isset( $delivery['delivery_mode'] ) ? sanitize_key( (string) $delivery['delivery_mode'] ) : 'otp_only';
		$magic_link_url = isset( $delivery['magic_link_url'] ) ? esc_url_raw( (string) $delivery['magic_link_url'] ) : '';

		if ( ! in_array( $delivery_mode, array( 'otp_only', 'otp_plus_link', 'link_only' ), true ) ) {
			$delivery_mode = 'otp_only';
		}

		if ( 'link_only' === $delivery_mode && '' === $magic_link_url ) {
			$delivery_mode = 'otp_only';
		}

		$message = $this->build_auth_message( $otp_code, (int) $ttl_seconds, $delivery_mode, $magic_link_url );
		$message = apply_filters(
			'mksddn_reddy_otp_message',
			$message,
			$reddy_id,
			(int) $ttl_seconds
		);

		$url     = self::API_DOMAIN . self::API_VERSION . $bot_token . self::SEND_ENDPOINT;
		$payload = array(
			'msg'     => (string) $message,
			'userKey' => $reddy_id,
		);

		$button_label = $this->resolve_magic_link_button_label();
		if ( '' !== $magic_link_url && in_array( $delivery_mode, array( 'otp_plus_link', 'link_only' ), true ) ) {
			$payload['keyboard'] = array(
				array(
					array(
						'type'  => 'command',
						'title' => $button_label,
						'data'  => $magic_link_url,
					),
				),
			);
		}

		/**
		 * Filter Reddy bot send payload before transport.
		 *
		 * @param array<string, mixed> $payload   Request payload.
		 * @param string               $reddy_id  Reddy user identifier.
		 * @param int                  $ttl_seconds OTP TTL.
		 */
		$payload = apply_filters( 'mksddn_reddy_send_payload', $payload, $reddy_id, (int) $ttl_seconds );

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
	 * Build auth message from admin template and placeholders.
	 *
	 * @param string $otp_code OTP value.
	 * @param int    $ttl_seconds OTP lifetime.
	 * @param string $delivery_mode Delivery mode.
	 * @param string $magic_link_url Magic link URL.
	 * @return string
	 */
	private function build_auth_message( $otp_code, $ttl_seconds, $delivery_mode, $magic_link_url ) {
		$template = $this->resolve_otp_message_template( $delivery_mode );
		$link     = $this->format_magic_link_for_message( $magic_link_url );

		$replacements = array(
			'{code}' => 'link_only' === $delivery_mode ? '' : $otp_code,
			'{ttl}'  => (string) $ttl_seconds,
			'{link}' => $link,
		);

		$message = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
		$message = trim( preg_replace( '/\s+/', ' ', $message ) );

		if ( 'link_only' === $delivery_mode && '' !== $magic_link_url ) {
			return $message;
		}

		return $message;
	}

	/**
	 * Format magic link for message body.
	 *
	 * Reddy clients support BBCode links; this improves clickability when
	 * integrators use the {link} placeholder in custom templates.
	 *
	 * @param string $magic_link_url Raw magic link URL.
	 * @return string
	 */
	private function format_magic_link_for_message( $magic_link_url ) {
		$magic_link_url = esc_url_raw( (string) $magic_link_url );
		if ( '' === $magic_link_url ) {
			return '';
		}

		return '[url=' . $magic_link_url . ']' . $magic_link_url . '[/url]';
	}

	/**
	 * Resolve OTP message template from settings.
	 *
	 * @param string $delivery_mode Delivery mode.
	 * @return string
	 */
	private function resolve_otp_message_template( $delivery_mode = 'otp_only' ) {
		$settings = get_option( Mksddn_Reddy_Auth_Settings_Page::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$defaults = Mksddn_Reddy_Auth_Settings_Page::get_install_defaults();

		if ( 'link_only' === $delivery_mode ) {
			$template = isset( $settings['magic_link_message_template'] ) ? trim( (string) $settings['magic_link_message_template'] ) : '';
			if ( '' === $template ) {
				return (string) $defaults['magic_link_message_template'];
			}

			return $template;
		}

		$template = isset( $settings['otp_message_template'] ) ? trim( (string) $settings['otp_message_template'] ) : '';

		if ( '' === $template || ( 'otp_only' === $delivery_mode && false === strpos( $template, '{code}' ) ) ) {
			return (string) $defaults['otp_message_template'];
		}

		return $template;
	}

	/**
	 * Resolve magic link button label from settings.
	 *
	 * @return string
	 */
	private function resolve_magic_link_button_label() {
		$settings = get_option( Mksddn_Reddy_Auth_Settings_Page::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$defaults = Mksddn_Reddy_Auth_Settings_Page::get_install_defaults();
		$label    = isset( $settings['magic_link_button_label'] ) ? trim( (string) $settings['magic_link_button_label'] ) : '';

		if ( '' === $label ) {
			return (string) $defaults['magic_link_button_label'];
		}

		return substr( $label, 0, 64 );
	}

	/**
	 * Resolve bot connection test message from settings.
	 *
	 * @return string
	 */
	private function resolve_bot_test_message() {
		$settings = get_option( Mksddn_Reddy_Auth_Settings_Page::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$defaults = Mksddn_Reddy_Auth_Settings_Page::get_install_defaults();
		$message  = isset( $settings['bot_test_message'] ) ? trim( (string) $settings['bot_test_message'] ) : '';

		if ( '' === $message ) {
			return (string) $defaults['bot_test_message'];
		}

		return $message;
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
