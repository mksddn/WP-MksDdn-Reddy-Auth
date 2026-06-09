<?php
/**
 * REST auth endpoints scaffold.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Rest_Auth_Controller {
	/**
	 * Cookie name for one-click polling context.
	 *
	 * @var string
	 */
	const POLLING_COOKIE_NAME = 'mksddn_reddy_polling';

	/**
	 * Cookie lifetime for one-click polling context.
	 *
	 * @var int
	 */
	const POLLING_COOKIE_TTL = 900;

	/**
	 * OTP service.
	 *
	 * @var Mksddn_Reddy_Auth_Otp_Service
	 */
	private $otp_service;

	/**
	 * Auth flow service.
	 *
	 * @var Mksddn_Reddy_Auth_Auth_Flow_Service
	 */
	private $auth_flow_service;

	/**
	 * Auth finalizer service.
	 *
	 * @var Mksddn_Reddy_Auth_Auth_Finalizer_Service
	 */
	private $auth_finalizer_service;

	/**
	 * Login intent service.
	 *
	 * @var Mksddn_Reddy_Auth_Login_Intent_Service
	 */
	private $login_intent_service;

	/**
	 * Session service.
	 *
	 * @var Mksddn_Reddy_Auth_Session_Service
	 */
	private $session_service;

	/**
	 * Token service.
	 *
	 * @var Mksddn_Reddy_Auth_Token_Service
	 */
	private $token_service;

	/**
	 * REST auth middleware.
	 *
	 * @var Mksddn_Reddy_Auth_Rest_Auth_Middleware
	 */
	private $auth_middleware;

	/**
	 * Request URL allowlist guard.
	 *
	 * @var Mksddn_Reddy_Auth_Request_Url_Guard
	 */
	private $request_url_guard;

	/**
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Otp_Service            $otp_service OTP service.
	 * @param Mksddn_Reddy_Auth_Auth_Flow_Service      $auth_flow_service Auth flow service.
	 * @param Mksddn_Reddy_Auth_Auth_Finalizer_Service $auth_finalizer_service Auth finalizer service.
	 * @param Mksddn_Reddy_Auth_Login_Intent_Service   $login_intent_service Login intent service.
	 * @param Mksddn_Reddy_Auth_Session_Service        $session_service Session service.
	 * @param Mksddn_Reddy_Auth_Token_Service          $token_service Token service.
	 * @param Mksddn_Reddy_Auth_Rest_Auth_Middleware   $auth_middleware Auth middleware.
	 * @param Mksddn_Reddy_Auth_Request_Url_Guard      $request_url_guard Request source guard.
	 */
	public function __construct( Mksddn_Reddy_Auth_Otp_Service $otp_service, Mksddn_Reddy_Auth_Auth_Flow_Service $auth_flow_service, Mksddn_Reddy_Auth_Auth_Finalizer_Service $auth_finalizer_service, Mksddn_Reddy_Auth_Login_Intent_Service $login_intent_service, Mksddn_Reddy_Auth_Session_Service $session_service, Mksddn_Reddy_Auth_Token_Service $token_service, Mksddn_Reddy_Auth_Rest_Auth_Middleware $auth_middleware, Mksddn_Reddy_Auth_Request_Url_Guard $request_url_guard ) {
		$this->otp_service            = $otp_service;
		$this->auth_flow_service      = $auth_flow_service;
		$this->auth_finalizer_service = $auth_finalizer_service;
		$this->login_intent_service   = $login_intent_service;
		$this->session_service        = $session_service;
		$this->token_service          = $token_service;
		$this->auth_middleware        = $auth_middleware;
		$this->request_url_guard      = $request_url_guard;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/send-code',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this->request_url_guard, 'rest_permission_check' ),
				'callback'            => array( $this, 'send_code' ),
				'args'                => array(
					'reddy_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this->request_url_guard, 'rest_permission_check' ),
				'callback'            => array( $this, 'login' ),
				'args'                => array(
					'reddy_id'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'code'          => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'issue_token'   => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'issue_session' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/intent-status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this->request_url_guard, 'rest_permission_check' ),
				'callback'            => array( $this, 'intent_status' ),
				'args'                => array(
					'intent_id'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'intent_secret' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/complete-intent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this->request_url_guard, 'rest_permission_check' ),
				'callback'            => array( $this, 'complete_intent' ),
				'args'                => array(
					'intent_id'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'intent_secret' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'issue_token'   => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'issue_session' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/button-callback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'button_callback' ),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'permission_logout' ),
				'callback'            => array( $this, 'logout' ),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'permission_me' ),
				'callback'            => array( $this, 'me' ),
			)
		);
	}

	/**
	 * Permission check for /auth/me (allowlist + auth).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function permission_me( WP_REST_Request $request ) {
		$source = $this->request_url_guard->rest_permission_check( $request );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		return $this->auth_middleware->authorize_request( $request );
	}

	/**
	 * Permission check for /auth/logout (allowlist + auth).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function permission_logout( WP_REST_Request $request ) {
		return $this->permission_me( $request );
	}

	/**
	 * Handle send-code endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function send_code( WP_REST_Request $request ) {
		$result = $this->auth_flow_service->request_login( (string) $request->get_param( 'reddy_id' ) );

		if ( is_wp_error( $result ) ) {
			return $this->error_response_from_wp_error( $result );
		}

		$response = array(
			'success' => true,
			'status'  => 'code_sent',
			'message' => __( 'OTP was sent successfully.', 'mksddn-reddy-auth' ),
		);

		if ( ! empty( $result['intent_id'] ) && ! empty( $result['intent_secret'] ) ) {
			$response['intent_id']     = (string) $result['intent_id'];
			$response['intent_secret'] = (string) $result['intent_secret'];
			$this->set_polling_context_cookie( (string) $result['intent_id'], (string) $result['intent_secret'] );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle login endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function login( WP_REST_Request $request ) {
		$reddy_id = (string) $request->get_param( 'reddy_id' );
		$result   = $this->otp_service->verify_code(
			$reddy_id,
			(string) $request->get_param( 'code' )
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_response_from_wp_error( $result );
		}

		$finalize = $this->auth_finalizer_service->finalize(
			$reddy_id,
			array(
				'issue_session' => rest_sanitize_boolean( $request->get_param( 'issue_session' ) ),
				'issue_token'   => rest_sanitize_boolean( $request->get_param( 'issue_token' ) ),
			)
		);

		if ( is_wp_error( $finalize ) ) {
			return $this->error_response_from_finalize( $finalize );
		}

		$response = array(
			'success' => true,
			'status'  => 'authenticated',
			'message' => __( 'Authentication successful.', 'mksddn-reddy-auth' ),
			'user'    => $this->auth_finalizer_service->format_user_payload( $finalize['user'] ),
		);

		if ( ! empty( $finalize['token'] ) && is_array( $finalize['token'] ) ) {
			$response = array_merge( $response, $finalize['token'] );
			if ( ! empty( $finalize['token']['access_token'] ) && empty( $response['token'] ) ) {
				$response['token'] = (string) $finalize['token']['access_token'];
			}
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle login intent polling endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function intent_status( WP_REST_Request $request ) {
		$credentials = $this->resolve_intent_credentials(
			(string) $request->get_param( 'intent_id' ),
			(string) $request->get_param( 'intent_secret' )
		);
		if ( is_wp_error( $credentials ) ) {
			return $this->error_response_from_wp_error( $credentials );
		}

		$result = $this->login_intent_service->get_status(
			(string) $credentials['intent_id'],
			(string) $credentials['intent_secret']
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_response_from_wp_error( $result );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => (string) $result['status'],
			),
			200
		);
	}

	/**
	 * Complete cross-device login after intent approval.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function complete_intent( WP_REST_Request $request ) {
		$credentials = $this->resolve_intent_credentials(
			(string) $request->get_param( 'intent_id' ),
			(string) $request->get_param( 'intent_secret' )
		);
		if ( is_wp_error( $credentials ) ) {
			return $this->error_response_from_wp_error( $credentials );
		}

		$reddy_id = $this->login_intent_service->consume_approved(
			(string) $credentials['intent_id'],
			(string) $credentials['intent_secret']
		);

		if ( is_wp_error( $reddy_id ) ) {
			return $this->error_response_from_wp_error( $reddy_id );
		}

		$finalize = $this->auth_finalizer_service->finalize(
			$reddy_id,
			array(
				'issue_session' => rest_sanitize_boolean( $request->get_param( 'issue_session' ) ),
				'issue_token'   => rest_sanitize_boolean( $request->get_param( 'issue_token' ) ),
			)
		);

		if ( is_wp_error( $finalize ) ) {
			return $this->error_response_from_finalize( $finalize );
		}

		$response = array(
			'success' => true,
			'status'  => 'authenticated',
			'message' => __( 'Authentication successful.', 'mksddn-reddy-auth' ),
			'user'    => $this->auth_finalizer_service->format_user_payload( $finalize['user'] ),
		);

		if ( ! empty( $finalize['token'] ) && is_array( $finalize['token'] ) ) {
			$response = array_merge( $response, $finalize['token'] );
			if ( ! empty( $finalize['token']['access_token'] ) && empty( $response['token'] ) ) {
				$response['token'] = (string) $finalize['token']['access_token'];
			}
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle Reddy bot buttonAction webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function button_callback( WP_REST_Request $request ) {
		$raw_body = $request->get_body();

		if ( ! $this->verify_reddy_signature( $raw_body, $request->get_header( 'X-BotAPI-Sign' ) ) ) {
			return new WP_REST_Response( array( 'success' => false ), 403 );
		}

		$payload = json_decode( $raw_body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		$updates = isset( $payload[0] ) ? $payload : array( $payload );

		foreach ( $updates as $update ) {
			if ( ! is_array( $update ) || ( isset( $update['type'] ) && 'buttonAction' !== $update['type'] ) ) {
				continue;
			}

			$intent_id = '';
			if ( ! empty( $update['button']['data'] ) ) {
				$intent_id = sanitize_text_field( (string) $update['button']['data'] );
			} elseif ( ! empty( $update['data'] ) ) {
				$intent_id = sanitize_text_field( (string) $update['data'] );
			}

			if ( '' !== $intent_id ) {
				$this->login_intent_service->approve( $intent_id );
			}
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Verify Reddy bot webhook signature.
	 *
	 * @param string $raw_body   Raw request body.
	 * @param string $signature  Value of X-BotAPI-Sign header.
	 * @return bool
	 */
	private function verify_reddy_signature( $raw_body, $signature ) {
		$bot_token = $this->get_bot_token();

		if ( '' === $bot_token ) {
			return false;
		}

		if ( '' === (string) $signature ) {
			return false;
		}

		$expected = hash( 'sha256', $raw_body . $bot_token );

		return hash_equals( $expected, strtolower( (string) $signature ) );
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

		return (string) get_option( Mksddn_Reddy_Auth_Reddy_Client::BOT_TOKEN_OPTION_KEY, '' );
	}

	/**
	 * Handle logout endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function logout( WP_REST_Request $request ) {
		unset( $request );

		$this->session_service->logout();

		$bearer = $this->token_service->get_bearer_token_from_request();
		if ( '' !== $bearer ) {
			$this->token_service->revoke_token( $bearer );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Logged out.', 'mksddn-reddy-auth' ),
			),
			200
		);
	}

	/**
	 * Handle current user endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function me( WP_REST_Request $request ) {
		unset( $request );

		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) || 0 === (int) $user->ID ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Authentication required.', 'mksddn-reddy-auth' ),
				),
				401
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'user'    => $this->auth_finalizer_service->format_user_payload( $user ),
			),
			200
		);
	}

	/**
	 * Build login error response with safe, actionable message.
	 *
	 * @param WP_Error $error Error object.
	 * @return WP_REST_Response
	 */
	private function error_response_from_wp_error( WP_Error $error ) {
		$code    = (string) $error->get_error_code();
		$status  = 400;
		$message = __( 'Unable to process authentication request.', 'mksddn-reddy-auth' );

		if ( 'rate_limited' === $code ) {
			$status  = 429;
			$message = __( 'Too many requests. Try again later.', 'mksddn-reddy-auth' );
		} elseif ( 'reddy_id_not_allowed' === $code ) {
			$status  = 403;
			$message = $error->get_error_message();
		} elseif ( 'intent_not_approved' === $code ) {
			$status  = 409;
			$message = __( 'Authorization is not confirmed yet.', 'mksddn-reddy-auth' );
		} elseif ( in_array( $code, array( 'identity_create_failed', 'invalid_identity' ), true ) ) {
			$message = __( 'Unable to create or resolve account. Contact the site administrator.', 'mksddn-reddy-auth' );
		} elseif ( in_array( $code, array( 'invalid_credentials', 'invalid_request' ), true ) ) {
			$message = __( 'OTP is invalid or expired. Request a new code and try again.', 'mksddn-reddy-auth' );
		} elseif ( in_array( $code, array( 'intent_context_mismatch' ), true ) ) {
			$status  = 403;
			$message = __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' );
		} elseif ( in_array( $code, array( 'invalid_intent', 'magic_link_storage_failed', 'intent_storage_failed', 'otp_generation_failed', 'otp_storage_failed' ), true ) ) {
			$message = __( 'Unable to process authentication request.', 'mksddn-reddy-auth' );
		}

		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}

	/**
	 * Build finalize error response.
	 *
	 * @param WP_Error $error Error object.
	 * @return WP_REST_Response
	 */
	private function error_response_from_finalize( WP_Error $error ) {
		$code = (string) $error->get_error_code();

		if ( in_array( $code, array( 'identity_create_failed', 'invalid_identity' ), true ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => $code,
					'message' => __( 'Unable to create or resolve account. Contact the site administrator.', 'mksddn-reddy-auth' ),
				),
				400
			);
		}

		if ( 'reddy_id_not_allowed' === $code ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => $code,
					'message' => $error->get_error_message(),
				),
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'message' => __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ),
			),
			500
		);
	}

	/**
	 * Persist one-click polling context in signed HttpOnly cookie.
	 *
	 * @param string $intent_id Intent ID.
	 * @param string $intent_secret Intent secret.
	 * @return void
	 */
	private function set_polling_context_cookie( $intent_id, $intent_secret ) {
		$intent_id     = sanitize_text_field( (string) $intent_id );
		$intent_secret = sanitize_text_field( (string) $intent_secret );

		if ( '' === $intent_id || '' === $intent_secret ) {
			return;
		}

		$expires_at = time() + self::POLLING_COOKIE_TTL;
		$payload    = array(
			'intent_id'     => $intent_id,
			'intent_secret' => $intent_secret,
			'expires_at'    => $expires_at,
		);
		$encoded    = base64_encode( (string) wp_json_encode( $payload ) );
		$signature  = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		$value      = $encoded . '.' . $signature;

		setcookie(
			self::POLLING_COOKIE_NAME,
			$value,
			$expires_at,
			$this->get_cookie_path(),
			$this->get_cookie_domain(),
			is_ssl(),
			true
		);
	}

	/**
	 * Ensure intent request is bound to current browser profile cookie context.
	 *
	 * @param string $intent_id Intent ID from request.
	 * @param string $intent_secret Intent secret from request.
	 * @return true|WP_Error
	 */
	private function resolve_intent_credentials( $intent_id, $intent_secret ) {
		$intent_id     = sanitize_text_field( (string) $intent_id );
		$intent_secret = sanitize_text_field( (string) $intent_secret );
		$context       = $this->get_polling_context_from_cookie();

		$has_cookie      = ! empty( $context['intent_id'] ) && ! empty( $context['intent_secret'] );
		$has_credentials = '' !== $intent_id && '' !== $intent_secret;

		if ( ! $has_cookie && ! $has_credentials ) {
			$error = new WP_Error( 'invalid_intent', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
			$this->emit_auth_failure( 'intent_resolve', $error );

			return $error;
		}

		if ( $has_credentials ) {
			if ( $has_cookie ) {
				$same_id     = hash_equals( (string) $context['intent_id'], $intent_id );
				$same_secret = hash_equals( (string) $context['intent_secret'], $intent_secret );
				if ( ! $same_id || ! $same_secret ) {
					$error = new WP_Error( 'intent_context_mismatch', __( 'Authorization request is invalid or expired.', 'mksddn-reddy-auth' ) );
					$this->emit_auth_failure( 'intent_resolve', $error );

					return $error;
				}
			}

			return array(
				'intent_id'     => $intent_id,
				'intent_secret' => $intent_secret,
			);
		}

		return array(
			'intent_id'     => (string) $context['intent_id'],
			'intent_secret' => (string) $context['intent_secret'],
		);
	}

	/**
	 * Read and validate one-click polling context from cookie.
	 *
	 * @return array<string, mixed>
	 */
	private function get_polling_context_from_cookie() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cookie access.
		if ( empty( $_COOKIE[ self::POLLING_COOKIE_NAME ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cookie access.
		$raw   = sanitize_text_field( (string) wp_unslash( $_COOKIE[ self::POLLING_COOKIE_NAME ] ) );
		$parts = explode( '.', $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			return array();
		}

		$encoded   = (string) $parts[0];
		$signature = (string) $parts[1];
		$expected  = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return array();
		}

		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return array();
		}

		$payload = json_decode( $decoded, true );
		if ( ! is_array( $payload ) || empty( $payload['intent_id'] ) || empty( $payload['intent_secret'] ) || empty( $payload['expires_at'] ) ) {
			return array();
		}

		if ( time() > (int) $payload['expires_at'] ) {
			return array();
		}

		return array(
			'intent_id'     => sanitize_text_field( (string) $payload['intent_id'] ),
			'intent_secret' => sanitize_text_field( (string) $payload['intent_secret'] ),
			'expires_at'    => (int) $payload['expires_at'],
		);
	}

	/**
	 * Return cookie path for plugin auth context.
	 *
	 * @return string
	 */
	private function get_cookie_path() {
		if ( defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ) {
			return COOKIEPATH;
		}

		return '/';
	}

	/**
	 * Return cookie domain for plugin auth context.
	 *
	 * @return string
	 */
	private function get_cookie_domain() {
		if ( defined( 'COOKIE_DOMAIN' ) ) {
			return (string) COOKIE_DOMAIN;
		}

		return '';
	}

	/**
	 * Emit auth failure observability event.
	 *
	 * @param string   $stage Auth stage key.
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	private function emit_auth_failure( $stage, WP_Error $error ) {
		do_action(
			'mksddn_reddy_auth_failure',
			array(
				'stage'      => sanitize_key( (string) $stage ),
				'error_code' => (string) $error->get_error_code(),
			)
		);
	}
}
