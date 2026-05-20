<?php
/**
 * REST middleware for cookie/Bearer authentication.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Rest_Auth_Middleware {
	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'mksddn_reddy_auth_settings';

	/**
	 * Token service.
	 *
	 * @var Mksddn_Reddy_Auth_Token_Service
	 */
	private $token_service;

	/**
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Token_Service $token_service Token service.
	 */
	public function __construct( Mksddn_Reddy_Auth_Token_Service $token_service ) {
		$this->token_service = $token_service;
	}

	/**
	 * Authorize protected route by WP cookie or Bearer token.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function authorize_request( WP_REST_Request $request ) {
		unset( $request );

		if ( is_user_logged_in() ) {
			return true;
		}

		$bearer = $this->token_service->get_bearer_token_from_request();
		if ( '' === $bearer ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'mksddn-reddy-auth' ),
				array( 'status' => 401 )
			);
		}

		$user = $this->token_service->validate_token( $bearer );
		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Authentication required.', 'mksddn-reddy-auth' ),
				array( 'status' => 401 )
			);
		}

		wp_set_current_user( (int) $user->ID );

		return true;
	}

	/**
	 * Enforce global REST content lock by Reddy auth setting.
	 *
	 * @param mixed $result Current auth result.
	 * @return mixed
	 */
	public function enforce_api_content_lock( $result ) {
		if ( ! $this->is_api_lock_enabled() ) {
			return $result;
		}

		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( $this->is_public_auth_route() ) {
			return $result;
		}

		if ( $this->is_reddy_session_authenticated() ) {
			return $result;
		}

		$bearer = $this->token_service->get_bearer_token_from_request();
		if ( '' === $bearer ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Reddy authentication required.', 'mksddn-reddy-auth' ),
				array( 'status' => 401 )
			);
		}

		$user = $this->token_service->validate_token( $bearer );
		if ( is_wp_error( $user ) || ! $this->user_has_reddy_identity( $user ) ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'Reddy authentication required.', 'mksddn-reddy-auth' ),
				array( 'status' => 401 )
			);
		}

		wp_set_current_user( (int) $user->ID );

		return $result;
	}

	/**
	 * Check whether global REST lock setting is enabled.
	 *
	 * @return bool
	 */
	private function is_api_lock_enabled() {
		$settings = get_option( self::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( ! isset( $settings['api_lock_enabled'] ) ) {
			return true;
		}

		return ! empty( $settings['api_lock_enabled'] );
	}

	/**
	 * Check whether monolith lock setting is enabled.
	 *
	 * @return bool
	 */
	private function is_monolith_lock_enabled() {
		$settings = get_option( self::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( ! isset( $settings['monolith_lock_enabled'] ) ) {
			return true;
		}

		return ! empty( $settings['monolith_lock_enabled'] );
	}

	/**
	 * True when current request is public auth endpoint.
	 *
	 * @return bool
	 */
	private function is_public_auth_route() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return false;
		}

		$path     = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$prefix   = '/' . rest_get_url_prefix() . '/' . Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE . '/auth/';
		$send     = $prefix . 'send-code';
		$login    = $prefix . 'login';

		return $this->path_ends_with( $path, $send ) || $this->path_ends_with( $path, $login );
	}

	/**
	 * Verify user authenticated by Reddy mapping.
	 *
	 * @return bool
	 */
	private function is_reddy_session_authenticated() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		return $this->user_has_reddy_identity( $user );
	}

	/**
	 * Enforce frontend content lock for monolith websites.
	 *
	 * @return void
	 */
	public function enforce_monolith_content_lock() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( ! $this->is_monolith_lock_enabled() ) {
			return;
		}

		if ( $this->is_reddy_session_authenticated() ) {
			return;
		}

		if ( $this->is_login_shortcode_page() ) {
			return;
		}

		$login_url = $this->resolve_login_url();
		if ( $this->is_current_request_url( $login_url ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'mksddn_reddy_status', 'auth_required', $login_url ) );
		exit;
	}

	/**
	 * Check if user has Reddy identity mapping.
	 *
	 * @param WP_User|null $user User instance.
	 * @return bool
	 */
	private function user_has_reddy_identity( $user ) {
		if ( ! ( $user instanceof WP_User ) || empty( $user->ID ) ) {
			return false;
		}

		return '' !== (string) get_user_meta( (int) $user->ID, Mksddn_Reddy_Auth_Identity_Service::REDDY_ID_META_KEY, true );
	}

	/**
	 * Polyfill-safe "ends with" checker for PHP 7.4+.
	 *
	 * @param string $haystack Full string.
	 * @param string $needle Tail string.
	 * @return bool
	 */
	private function path_ends_with( $haystack, $needle ) {
		$needle_length = strlen( $needle );
		if ( 0 === $needle_length ) {
			return true;
		}

		return substr( $haystack, -$needle_length ) === $needle;
	}

	/**
	 * Detect if current singular page renders login shortcode.
	 *
	 * @return bool
	 */
	private function is_login_shortcode_page() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post || empty( $post->post_content ) ) {
			return false;
		}

		return false !== strpos( (string) $post->post_content, '[mksddn_reddy_login]' );
	}

	/**
	 * Resolve login URL from settings or shortcode page fallback.
	 *
	 * @return string
	 */
	private function resolve_login_url() {
		$settings = get_option( self::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$page_id  = isset( $settings['login_page_id'] ) ? absint( $settings['login_page_id'] ) : 0;
		$url      = isset( $settings['login_page_url'] ) ? esc_url_raw( (string) $settings['login_page_url'] ) : '';

		if ( $page_id > 0 ) {
			$page_url = get_permalink( $page_id );
			if ( $page_url ) {
				return (string) $page_url;
			}
		}

		if ( '' !== $url ) {
			return $url;
		}

		$fallback = $this->find_first_login_shortcode_page_url();
		if ( '' !== $fallback ) {
			return $fallback;
		}

		return home_url( '/' );
	}

	/**
	 * Find first published page containing login shortcode.
	 *
	 * @return string
	 */
	private function find_first_login_shortcode_page_url() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => '[mksddn_reddy_login]',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $pages ) || ! isset( $pages[0] ) ) {
			return '';
		}

		$url = get_permalink( $pages[0] );

		return $url ? (string) $url : '';
	}

	/**
	 * Compare current URL path with target URL path.
	 *
	 * @param string $target_url Target URL.
	 * @return bool
	 */
	private function is_current_request_url( $target_url ) {
		$target_path = (string) wp_parse_url( (string) $target_url, PHP_URL_PATH );
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		return '' !== $target_path && $target_path === $request_path;
	}
}
