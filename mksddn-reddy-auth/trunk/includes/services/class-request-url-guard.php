<?php
/**
 * Restrict plugin REST traffic to configured client URLs.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Request_Url_Guard {
	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'mksddn_reddy_auth_settings';

	/**
	 * Block disallowed clients before REST route dispatch.
	 *
	 * @param mixed           $result  Response to replace dispatch, or null.
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function enforce_rest_url_allowlist( $result, $server, $request ) {
		unset( $server );

		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( ! $this->is_plugin_rest_route( $route ) ) {
			return $result;
		}

		if ( $this->is_request_allowed() ) {
			return $result;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Request not allowed from this source.', 'mksddn-reddy-auth' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * True when allowlist is empty or request Origin/Referer matches a configured URL.
	 *
	 * @return bool
	 */
	public function is_request_allowed() {
		$allowed_urls = $this->get_allowed_urls();
		if ( empty( $allowed_urls ) ) {
			return true;
		}

		$request_url = $this->get_request_source_url();
		$allowed     = $this->url_matches_allowlist( $request_url, $allowed_urls );

		/**
		 * Filter whether the current request source URL is allowed.
		 *
		 * @param bool   $allowed      Whether the URL is allowed.
		 * @param string $request_url  Resolved Origin or Referer URL.
		 * @param array  $allowed_urls Configured allowlist entries.
		 */
		return (bool) apply_filters( 'mksddn_reddy_is_request_url_allowed', $allowed, $request_url, $allowed_urls );
	}

	/**
	 * Return configured allowlist entries.
	 *
	 * @return array<int, string>
	 */
	public function get_allowed_urls() {
		$settings = get_option( self::SETTINGS_OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$allowed  = isset( $settings['allowed_urls'] ) ? $settings['allowed_urls'] : array();

		if ( ! is_array( $allowed ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $allowed as $entry ) {
			$entry = $this->normalize_url_entry( (string) $entry );
			if ( '' !== $entry ) {
				$normalized[] = $entry;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Resolve request source from Origin or Referer header.
	 *
	 * @return string
	 */
	public function get_request_source_url() {
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			return $this->normalize_url_entry( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) );
		}

		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			return $this->normalize_url_entry( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
		}

		return '';
	}

	/**
	 * Sanitize allowlist textarea input into validated URL entries.
	 *
	 * @param mixed $raw Raw settings value.
	 * @return array<int, string>
	 */
	public function sanitize_allowed_urls( $raw ) {
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
			$entry = $this->normalize_url_entry( trim( (string) $line ) );
			if ( '' !== $entry ) {
				$allowed[] = $entry;
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Normalize URL for comparison (scheme, host, port, optional path).
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	public function normalize_url_entry( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$url   = esc_url_raw( $url );
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );
		$port = '';
		if ( isset( $parts['port'] ) ) {
			$port_number = (int) $parts['port'];
			$is_default  = ( 'https' === $scheme && 443 === $port_number ) || ( 'http' === $scheme && 80 === $port_number );
			if ( ! $is_default ) {
				$port = ':' . $port_number;
			}
		}

		$path = '';
		if ( ! empty( $parts['path'] ) && '/' !== (string) $parts['path'] ) {
			$path = untrailingslashit( (string) $parts['path'] );
		}

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Check whether route belongs to this plugin REST namespace.
	 *
	 * @param string $route REST route path.
	 * @return bool
	 */
	private function is_plugin_rest_route( $route ) {
		$prefix = '/' . Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE;

		return 0 === strpos( $route, $prefix );
	}

	/**
	 * Match request URL against configured allowlist entries.
	 *
	 * @param string             $request_url  Normalized request source URL.
	 * @param array<int, string> $allowed_urls Allowlist entries.
	 * @return bool
	 */
	private function url_matches_allowlist( $request_url, array $allowed_urls ) {
		if ( '' === $request_url ) {
			return false;
		}

		foreach ( $allowed_urls as $entry ) {
			if ( $request_url === $entry || $this->url_starts_with( $request_url, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prefix match with boundary at path segment or end of string.
	 *
	 * @param string $request_url Request URL.
	 * @param string $entry       Allowlist entry.
	 * @return bool
	 */
	private function url_starts_with( $request_url, $entry ) {
		if ( 0 !== strpos( $request_url, $entry ) ) {
			return false;
		}

		if ( strlen( $request_url ) === strlen( $entry ) ) {
			return true;
		}

		$next = $request_url[ strlen( $entry ) ];

		return '/' === $next;
	}
}
