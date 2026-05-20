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
	 * Identity service.
	 *
	 * @var Mksddn_Reddy_Auth_Identity_Service
	 */
	private $identity_service;

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
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Otp_Service          $otp_service OTP service.
	 * @param Mksddn_Reddy_Auth_Identity_Service     $identity_service Identity service.
	 * @param Mksddn_Reddy_Auth_Session_Service      $session_service Session service.
	 * @param Mksddn_Reddy_Auth_Token_Service        $token_service Token service.
	 * @param Mksddn_Reddy_Auth_Rest_Auth_Middleware $auth_middleware Auth middleware.
	 */
	public function __construct( Mksddn_Reddy_Auth_Otp_Service $otp_service, Mksddn_Reddy_Auth_Identity_Service $identity_service, Mksddn_Reddy_Auth_Session_Service $session_service, Mksddn_Reddy_Auth_Token_Service $token_service, Mksddn_Reddy_Auth_Rest_Auth_Middleware $auth_middleware ) {
		$this->otp_service      = $otp_service;
		$this->identity_service = $identity_service;
		$this->session_service  = $session_service;
		$this->token_service    = $token_service;
		$this->auth_middleware  = $auth_middleware;
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
				'permission_callback' => '__return_true',
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
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'login' ),
				'args'                => array(
					'reddy_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'code'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'issue_token' => array(
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
					),
				),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'logout' ),
			)
		);

		register_rest_route(
			Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE,
			'/auth/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this->auth_middleware, 'authorize_request' ),
				'callback'            => array( $this, 'me' ),
			)
		);
	}

	/**
	 * Handle send-code endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function send_code( WP_REST_Request $request ) {
		$result = $this->otp_service->request_code( (string) $request->get_param( 'reddy_id' ) );

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

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'OTP was sent successfully.', 'mksddn-reddy-auth' ),
			),
			200
		);
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

		$user = $this->identity_service->resolve_or_create_user( $reddy_id );
		if ( is_wp_error( $user ) ) {
			return $this->error_response_from_wp_error( $user );
		}

		$session_result = $this->session_service->login( $user );
		if ( is_wp_error( $session_result ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Unable to create session.', 'mksddn-reddy-auth' ),
				),
				500
			);
		}

		do_action( 'mksddn_reddy_after_login', $user, $reddy_id );

		$response = array(
			'success' => true,
			'message' => __( 'Authentication successful.', 'mksddn-reddy-auth' ),
			'user'    => array(
				'id'           => (int) $user->ID,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
			),
		);

		if ( rest_sanitize_boolean( $request->get_param( 'issue_token' ) ) ) {
			$token_result = $this->token_service->issue_token( (int) $user->ID );
			if ( is_wp_error( $token_result ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Unable to issue token.', 'mksddn-reddy-auth' ),
					),
					500
				);
			}

			$response = array_merge( $response, $token_result );
		}

		return new WP_REST_Response(
			$response,
			200
		);
	}

	/**
	 * Handle logout endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function logout( WP_REST_Request $request ) {
		// Request is reserved for future token revocation payload.
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
				'user'    => array(
					'id'           => (int) $user->ID,
					'display_name' => $user->display_name,
					'email'        => $user->user_email,
				),
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
}
