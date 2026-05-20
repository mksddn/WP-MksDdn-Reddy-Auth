<?php
/**
 * Manage WordPress cookie sessions for monolith mode.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Session_Service {
	/**
	 * Log in user via native WordPress auth cookies.
	 *
	 * @param WP_User $user WordPress user.
	 * @return true|WP_Error
	 */
	public function login( WP_User $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return new WP_Error( 'session_user_required', __( 'Unable to create session.', 'mksddn-reddy-auth' ) );
		}

		if ( headers_sent() ) {
			return new WP_Error( 'session_headers_sent', __( 'Unable to create session.', 'mksddn-reddy-auth' ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );

		return true;
	}

	/**
	 * Log out currently authenticated user.
	 *
	 * @return void
	 */
	public function logout() {
		wp_logout();
	}
}
