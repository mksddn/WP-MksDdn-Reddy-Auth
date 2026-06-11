<?php
/**
 * Restrict authentication to configured Reddy user identifiers.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Reddy_Id_Whitelist_Service {
	/**
	 * Return configured whitelist entries.
	 *
	 * @return array<int, string>
	 */
	public static function get_allowed_reddy_ids() {
		$settings = Mksddn_Reddy_Auth_Settings_Page::get_runtime_settings();
		$allowed  = isset( $settings['allowed_reddy_ids'] ) ? $settings['allowed_reddy_ids'] : array();

		if ( is_string( $allowed ) ) {
			$allowed = self::sanitize_allowed_reddy_ids( $allowed );
		}

		if ( ! is_array( $allowed ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $allowed as $entry ) {
			$entry = sanitize_text_field( trim( (string) $entry ) );
			if ( '' !== $entry ) {
				$normalized[] = $entry;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * True when whitelist is empty or reddy_id is listed.
	 *
	 * @param string $reddy_id Reddy user identifier.
	 * @return bool
	 */
	public static function is_allowed( $reddy_id ) {
		$reddy_id    = sanitize_text_field( (string) $reddy_id );
		$allowed_ids = self::get_allowed_reddy_ids();

		if ( empty( $allowed_ids ) ) {
			$allowed = true;
		} else {
			$allowed = in_array( $reddy_id, $allowed_ids, true );
		}

		/**
		 * Filter whether a Reddy ID is allowed to authenticate.
		 *
		 * @param bool   $allowed     Whether the Reddy ID is allowed.
		 * @param string $reddy_id    Reddy user identifier.
		 * @param array  $allowed_ids Configured whitelist entries.
		 */
		return (bool) apply_filters( 'mksddn_reddy_is_reddy_id_allowed', $allowed, $reddy_id, $allowed_ids );
	}

	/**
	 * Build a standard error for blocked Reddy IDs.
	 *
	 * @return WP_Error
	 */
	public static function not_allowed_error() {
		return new WP_Error(
			'reddy_id_not_allowed',
			__( 'Unable to process authentication request.', 'mksddn-reddy-auth' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Sanitize whitelist textarea input into Reddy ID entries.
	 *
	 * @param mixed $raw Raw settings value.
	 * @return array<int, string>
	 */
	public static function sanitize_allowed_reddy_ids( $raw ) {
		if ( is_array( $raw ) ) {
			$lines = $raw;
		} else {
			$lines = preg_split( '/[\r\n,]+/', (string) $raw );
		}

		if ( ! is_array( $lines ) ) {
			return array();
		}

		$allowed = array();
		foreach ( $lines as $line ) {
			$entry = sanitize_text_field( trim( (string) $line ) );
			if ( '' !== $entry ) {
				$allowed[] = $entry;
			}
		}

		return array_values( array_unique( $allowed ) );
	}
}
