<?php
/**
 * Orchestrates OTP, magic link, and login intent for send-code flows.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Auth_Flow_Service {
	/**
	 * OTP service.
	 *
	 * @var Mksddn_Reddy_Auth_Otp_Service
	 */
	private $otp_service;

	/**
	 * Magic link service.
	 *
	 * @var Mksddn_Reddy_Auth_Magic_Link_Service
	 */
	private $magic_link_service;

	/**
	 * Login intent service.
	 *
	 * @var Mksddn_Reddy_Auth_Login_Intent_Service
	 */
	private $login_intent_service;

	/**
	 * Reddy client.
	 *
	 * @var Mksddn_Reddy_Auth_Reddy_Client
	 */
	private $reddy_client;

	/**
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Otp_Service         $otp_service OTP service.
	 * @param Mksddn_Reddy_Auth_Magic_Link_Service  $magic_link_service Magic link service.
	 * @param Mksddn_Reddy_Auth_Login_Intent_Service $login_intent_service Login intent service.
	 * @param Mksddn_Reddy_Auth_Reddy_Client        $reddy_client Reddy client.
	 */
	public function __construct( Mksddn_Reddy_Auth_Otp_Service $otp_service, Mksddn_Reddy_Auth_Magic_Link_Service $magic_link_service, Mksddn_Reddy_Auth_Login_Intent_Service $login_intent_service, Mksddn_Reddy_Auth_Reddy_Client $reddy_client ) {
		$this->otp_service          = $otp_service;
		$this->magic_link_service   = $magic_link_service;
		$this->login_intent_service = $login_intent_service;
		$this->reddy_client         = $reddy_client;
	}

	/**
	 * Request authentication credentials and optional one-click delivery.
	 *
	 * @param string $reddy_id Reddy user identifier.
	 * @return array<string, mixed>|WP_Error
	 */
	public function request_login( $reddy_id ) {
		$reddy_id = sanitize_text_field( (string) $reddy_id );
		$settings = $this->get_settings();

		$intent_payload = null;
		$magic_link_url = '';

		if ( 'otp_only' !== $this->resolve_delivery_mode( $settings ) ) {
			$intent = $this->login_intent_service->create( $reddy_id );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}

			$magic = $this->magic_link_service->issue( $reddy_id, $intent['id'] );
			if ( is_wp_error( $magic ) ) {
				return $magic;
			}

			$intent_payload = $intent;
			$magic_link_url = (string) $magic['url'];
		}

		$delivery_mode = $this->resolve_delivery_mode( $settings );
		$otp_result    = $this->otp_service->request_code(
			$reddy_id,
			array(
				'delivery_mode'  => $delivery_mode,
				'magic_link_url' => $magic_link_url,
			)
		);

		if ( is_wp_error( $otp_result ) ) {
			return $otp_result;
		}

		$response = array(
			'success' => true,
		);

		if ( is_array( $intent_payload ) ) {
			$response['intent_id']     = (string) $intent_payload['id'];
			$response['intent_secret'] = (string) $intent_payload['secret'];
		}

		return $response;
	}

	/**
	 * Check whether one-click auth is enabled in settings.
	 *
	 * @param array<string, mixed>|null $settings Optional settings array.
	 * @return bool
	 */
	public function is_one_click_enabled( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : $this->get_settings();

		return 'otp_only' !== $this->resolve_delivery_mode( $settings );
	}

	/**
	 * Resolve delivery mode from settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return string
	 */
	private function resolve_delivery_mode( array $settings ) {
		if ( array_key_exists( 'one_click_enabled', $settings ) && empty( $settings['one_click_enabled'] ) ) {
			return 'otp_only';
		}

		$mode = isset( $settings['one_click_delivery_mode'] ) ? sanitize_key( (string) $settings['one_click_delivery_mode'] ) : 'otp_plus_link';
		$modes = array( 'otp_only', 'otp_plus_link', 'link_only' );

		if ( ! in_array( $mode, $modes, true ) ) {
			return 'otp_plus_link';
		}

		return $mode;
	}

	/**
	 * Return merged plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings() {
		$raw = get_option( Mksddn_Reddy_Auth_Settings_Page::SETTINGS_OPTION_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();

		return wp_parse_args( $raw, Mksddn_Reddy_Auth_Settings_Page::get_install_defaults() );
	}
}
