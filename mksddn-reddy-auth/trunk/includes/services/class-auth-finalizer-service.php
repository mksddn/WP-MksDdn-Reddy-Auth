<?php
/**
 * Shared post-authentication pipeline for OTP and one-click flows.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Auth_Finalizer_Service {
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
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Identity_Service $identity_service Identity service.
	 * @param Mksddn_Reddy_Auth_Session_Service  $session_service Session service.
	 * @param Mksddn_Reddy_Auth_Token_Service    $token_service Token service.
	 */
	public function __construct( Mksddn_Reddy_Auth_Identity_Service $identity_service, Mksddn_Reddy_Auth_Session_Service $session_service, Mksddn_Reddy_Auth_Token_Service $token_service ) {
		$this->identity_service = $identity_service;
		$this->session_service  = $session_service;
		$this->token_service    = $token_service;
	}

	/**
	 * Resolve user and optionally issue session or bearer token.
	 *
	 * @param string               $reddy_id Reddy user identifier.
	 * @param array<string, mixed> $options  issue_session, issue_token flags.
	 * @return array<string, mixed>|WP_Error
	 */
	public function finalize( $reddy_id, array $options = array() ) {
		$reddy_id      = sanitize_text_field( (string) $reddy_id );
		$issue_session = ! empty( $options['issue_session'] );
		$issue_token   = ! empty( $options['issue_token'] );

		if ( ! Mksddn_Reddy_Auth_Reddy_Id_Whitelist_Service::is_allowed( $reddy_id ) ) {
			$error = Mksddn_Reddy_Auth_Reddy_Id_Whitelist_Service::not_allowed_error();
			$this->emit_auth_failure( 'finalize_whitelist', $reddy_id, $error );

			return $error;
		}

		$user = $this->identity_service->resolve_or_create_user( $reddy_id );
		if ( is_wp_error( $user ) ) {
			$this->emit_auth_failure( 'finalize_identity', $reddy_id, $user );
			return $user;
		}

		if ( $issue_session ) {
			$session_result = $this->session_service->login( $user );
			if ( is_wp_error( $session_result ) ) {
				$error = new WP_Error( 'session_failed', __( 'Unable to create session.', 'mksddn-reddy-auth' ) );
				$this->emit_auth_failure( 'finalize_session', $reddy_id, $error );

				return $error;
			}
		}

		do_action( 'mksddn_reddy_after_login', $user, $reddy_id );

		$response = array(
			'user' => $user,
		);

		if ( $issue_token ) {
			$token_result = $this->token_service->issue_token( (int) $user->ID );
			if ( is_wp_error( $token_result ) ) {
				$error = new WP_Error( 'token_failed', __( 'Unable to issue token.', 'mksddn-reddy-auth' ) );
				$this->emit_auth_failure( 'finalize_token', $reddy_id, $error );

				return $error;
			}

			$response['token'] = $token_result;
		}

		return $response;
	}

	/**
	 * Emit lightweight observability event for auth failures.
	 *
	 * @param string   $stage Auth stage key.
	 * @param string   $reddy_id Reddy ID context.
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	private function emit_auth_failure( $stage, $reddy_id, WP_Error $error ) {
		do_action(
			'mksddn_reddy_auth_failure',
			array(
				'stage'      => sanitize_key( (string) $stage ),
				'reddy_id'   => sanitize_text_field( (string) $reddy_id ),
				'error_code' => (string) $error->get_error_code(),
			)
		);
	}

	/**
	 * Build standard user payload for REST responses.
	 *
	 * @param WP_User $user WordPress user.
	 * @return array<string, mixed>
	 */
	public function format_user_payload( WP_User $user ) {
		return array(
			'id'           => (int) $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
		);
	}
}
