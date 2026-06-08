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
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'intent_secret' => array(
						'required'          => true,
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
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'intent_secret' => array(
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
			$status = 'rate_limited' === $result->get_error_code() ? 429 : 400;

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				$status
			);
		}

		$response = array(
			'success' => true,
			'message' => __( 'OTP was sent successfully.', 'mksddn-reddy-auth' ),
		);

		if ( ! empty( $result['intent_id'] ) && ! empty( $result['intent_secret'] ) ) {
			$response['intent_id']     = (string) $result['intent_id'];
			$response['intent_secret'] = (string) $result['intent_secret'];
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
			'message' => __( 'Authentication successful.', 'mksddn-reddy-auth' ),
			'user'    => $this->auth_finalizer_service->format_user_payload( $finalize['user'] ),
		);

		if ( ! empty( $finalize['token'] ) && is_array( $finalize['token'] ) ) {
			$response = array_merge( $response, $finalize['token'] );
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
		$result = $this->login_intent_service->get_status(
			(string) $request->get_param( 'intent_id' ),
			(string) $request->get_param( 'intent_secret' )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
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
		$reddy_id = $this->login_intent_service->consume_approved(
			(string) $request->get_param( 'intent_id' ),
			(string) $request->get_param( 'intent_secret' )
		);

		if ( is_wp_error( $reddy_id ) ) {
			$status = 'intent_not_approved' === $reddy_id->get_error_code() ? 409 : 400;

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $reddy_id->get_error_message(),
					'code'    => $reddy_id->get_error_code(),
				),
				$status
			);
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
			'message' => __( 'Authentication successful.', 'mksddn-reddy-auth' ),
			'user'    => $this->auth_finalizer_service->format_user_payload( $finalize['user'] ),
		);

		if ( ! empty( $finalize['token'] ) && is_array( $finalize['token'] ) ) {
			$response = array_merge( $response, $finalize['token'] );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle logout endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function logout( WP_REST_Request $request ) {
		unset( $request );

		$session_service = $this->session_service;
		$session_service->logout();

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
		$message = __( 'Invalid credentials.', 'mksddn-reddy-auth' );

		if ( 'rate_limited' === $code ) {
			$status  = 429;
			$message = $error->get_error_message();
		} elseif ( 'reddy_id_not_allowed' === $code ) {
			$status  = 403;
			$message = $error->get_error_message();
		} elseif ( in_array( $code, array( 'identity_create_failed', 'invalid_identity' ), true ) ) {
			$message = __( 'Unable to create or resolve account. Contact the site administrator.', 'mksddn-reddy-auth' );
		} elseif ( in_array( $code, array( 'invalid_credentials', 'invalid_request' ), true ) ) {
			$message = __( 'OTP is invalid or expired. Request a new code and try again.', 'mksddn-reddy-auth' );
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
				'message' => $error->get_error_message(),
			),
			500
		);
	}
}
