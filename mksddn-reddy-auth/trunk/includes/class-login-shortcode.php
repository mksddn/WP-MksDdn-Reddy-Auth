<?php
/**
 * Login shortcode for monolith WordPress flow.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Login_Shortcode {
	/**
	 * Cookie name for login step context.
	 *
	 * @var string
	 */
	const LOGIN_CONTEXT_COOKIE_NAME = 'mksddn_reddy_login_context';

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
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Otp_Service            $otp_service OTP service.
	 * @param Mksddn_Reddy_Auth_Auth_Flow_Service      $auth_flow_service Auth flow service.
	 * @param Mksddn_Reddy_Auth_Auth_Finalizer_Service $auth_finalizer_service Auth finalizer service.
	 * @param Mksddn_Reddy_Auth_Magic_Link_Service     $magic_link_service Magic link service.
	 * @param Mksddn_Reddy_Auth_Login_Intent_Service   $login_intent_service Login intent service.
	 */
	public function __construct( Mksddn_Reddy_Auth_Otp_Service $otp_service, Mksddn_Reddy_Auth_Auth_Flow_Service $auth_flow_service, Mksddn_Reddy_Auth_Auth_Finalizer_Service $auth_finalizer_service, Mksddn_Reddy_Auth_Magic_Link_Service $magic_link_service, Mksddn_Reddy_Auth_Login_Intent_Service $login_intent_service ) {
		$this->otp_service            = $otp_service;
		$this->auth_flow_service      = $auth_flow_service;
		$this->auth_finalizer_service = $auth_finalizer_service;
		$this->magic_link_service     = $magic_link_service;
		$this->login_intent_service   = $login_intent_service;
	}

	/**
	 * Register shortcode and form handlers.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_shortcode( 'mksddn_reddy_login', array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_nopriv_mksddn_reddy_send_code', array( $this, 'handle_send_code' ) );
		add_action( 'admin_post_mksddn_reddy_send_code', array( $this, 'handle_send_code' ) );
		add_action( 'admin_post_nopriv_mksddn_reddy_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_mksddn_reddy_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_nopriv_mksddn_reddy_verify_link', array( $this, 'handle_verify_link' ) );
		add_action( 'admin_post_mksddn_reddy_verify_link', array( $this, 'handle_verify_link' ) );
	}

	/**
	 * Render login form.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		if ( is_user_logged_in() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag after redirect.
		$status = isset( $_GET['mksddn_reddy_status'] ) ? sanitize_key( wp_unslash( $_GET['mksddn_reddy_status'] ) ) : '';
		$message = '';

		if ( isset( $_GET['mksddn_reddy_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice after redirect.
			$message = sanitize_text_field( urldecode( (string) wp_unslash( $_GET['mksddn_reddy_message'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on assignment.
		}

		if ( '' === $message ) {
			$message = $this->status_to_message( $status );
		}

		$code_step_statuses = array( 'code_sent', 'invalid_credentials' );
		$is_code_step       = in_array( $status, $code_step_statuses, true );

		if ( ! $is_code_step ) {
			$this->clear_polling_context_cookie();
			$this->clear_login_context_cookie();
		}

		$polling_context = $this->get_polling_context_from_cookie();
		$intent_id       = isset( $polling_context['intent_id'] ) ? (string) $polling_context['intent_id'] : '';
		$intent_secret   = isset( $polling_context['intent_secret'] ) ? (string) $polling_context['intent_secret'] : '';
		$expires_at      = isset( $polling_context['expires_at'] ) ? (int) $polling_context['expires_at'] : 0;
		$login_reddy_id  = $this->get_login_reddy_id_from_cookie();
		$delivery_mode   = $this->auth_flow_service->get_delivery_mode();
		$show_code_step  = ( $is_code_step && '' !== $login_reddy_id && 'link_only' !== $delivery_mode );
		$awaiting_approval = ( 'code_sent' === $status && '' !== $intent_id && '' !== $intent_secret && $this->auth_flow_service->is_one_click_enabled() );

		$this->enqueue_assets( $awaiting_approval, $intent_id, $intent_secret, $expires_at );

		ob_start();
		?>
		<div class="mksddn-reddy-auth-form" data-mksddn-reddy-auth-form="1">
			<?php if ( '' !== $message ) : ?>
				<p class="mksddn-reddy-auth-message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>

			<?php if ( $awaiting_approval ) : ?>
				<p class="mksddn-reddy-auth-waiting">
					<?php echo esc_html__( 'Waiting for authorization in Reddy. You can also enter the code below.', 'mksddn-reddy-auth' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $show_code_step ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-reddy-auth-login-form">
					<?php wp_nonce_field( 'mksddn_reddy_login_action' ); ?>
					<input type="hidden" name="action" value="mksddn_reddy_login" />
					<input type="hidden" name="reddy_id" value="<?php echo esc_attr( $login_reddy_id ); ?>" />
					<p>
						<label for="mksddn-reddy-code"><?php echo esc_html__( 'One-time code', 'mksddn-reddy-auth' ); ?></label><br />
						<input id="mksddn-reddy-code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" required />
					</p>
					<p>
						<button type="submit"><?php echo esc_html__( 'Log in', 'mksddn-reddy-auth' ); ?></button>
					</p>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-reddy-auth-send-form">
					<?php wp_nonce_field( 'mksddn_reddy_send_code_action' ); ?>
					<input type="hidden" name="action" value="mksddn_reddy_send_code" />
					<p>
						<label for="mksddn-reddy-id-send"><?php echo esc_html__( 'Reddy ID', 'mksddn-reddy-auth' ); ?></label><br />
						<input id="mksddn-reddy-id-send" type="text" name="reddy_id" required />
					</p>
					<p>
						<button type="submit"><?php echo esc_html__( 'Send code', 'mksddn-reddy-auth' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Handle send-code form request.
	 *
	 * @return void
	 */
	public function handle_send_code() {
		check_admin_referer( 'mksddn_reddy_send_code_action' );

		$reddy_id = isset( $_POST['reddy_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reddy_id'] ) ) : '';
		$result   = $this->auth_flow_service->request_login( $reddy_id );

		if ( is_wp_error( $result ) ) {
			$this->clear_login_context_cookie();
			$this->redirect_with_status( 'error', $this->public_message_from_error( $result ) );
		}

		$this->set_login_context_cookie( $reddy_id );

		if ( ! empty( $result['intent_id'] ) && ! empty( $result['intent_secret'] ) ) {
			$this->set_polling_context_cookie( (string) $result['intent_id'], (string) $result['intent_secret'] );
		}

		$this->redirect_with_status( 'code_sent' );
	}

	/**
	 * Handle login form request.
	 *
	 * @return void
	 */
	public function handle_login() {
		check_admin_referer( 'mksddn_reddy_login_action' );

		$posted_reddy_id = isset( $_POST['reddy_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reddy_id'] ) ) : '';
		$stored_reddy_id = $this->get_login_reddy_id_from_cookie();
		$reddy_id        = '' !== $stored_reddy_id ? $stored_reddy_id : $posted_reddy_id;
		$code            = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( '' === $reddy_id || '' === $code ) {
			$this->redirect_with_status( 'invalid_credentials' );
		}

		$otp_result = $this->otp_service->verify_code( $reddy_id, $code );
		if ( is_wp_error( $otp_result ) ) {
			$this->redirect_with_status( 'invalid_credentials' );
		}

		$finalize = $this->auth_finalizer_service->finalize(
			$reddy_id,
			array(
				'issue_session' => true,
			)
		);

		if ( is_wp_error( $finalize ) ) {
			$this->redirect_with_status( 'error' );
		}

		$this->clear_polling_context_cookie();
		$this->clear_login_context_cookie();
		$this->redirect_with_status( 'logged_in' );
	}

	/**
	 * Handle magic link verification from messenger button.
	 *
	 * @return void
	 */
	public function handle_verify_link() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-time token is the secret.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$result = $this->magic_link_service->verify_and_consume( $token );

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Authorization failed', 'mksddn-reddy-auth' ),
				array( 'response' => 403 )
			);
		}

		$approve_result = $this->login_intent_service->approve(
			(string) $result['intent_id'],
			'',
			isset( $result['reddy_id'] ) ? (string) $result['reddy_id'] : ''
		);
		if ( is_wp_error( $approve_result ) && 'intent_consumed' !== $approve_result->get_error_code() ) {
			wp_die(
				esc_html( $approve_result->get_error_message() ),
				esc_html__( 'Authorization failed', 'mksddn-reddy-auth' ),
				array( 'response' => 403 )
			);
		}

		$redirect_url = add_query_arg(
			'mksddn_reddy_status',
			'one_click_confirmed',
			$this->resolve_post_login_redirect_url()
		);
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Redirect back with status query argument.
	 *
	 * @param string               $status  Status key.
	 * @param string               $message Optional user-visible message.
	 * @param array<string, string> $extra  Optional query args.
	 * @return void
	 */
	private function redirect_with_status( $status, $message = '', array $extra = array() ) {
		$redirect_url = wp_get_referer();
		$redirect_url = $redirect_url ? $redirect_url : home_url( '/' );
		$redirect_url = add_query_arg( 'mksddn_reddy_status', sanitize_key( $status ), $redirect_url );

		if ( '' !== $message ) {
			$redirect_url = add_query_arg( 'mksddn_reddy_message', sanitize_text_field( $message ), $redirect_url );
		}

		foreach ( $extra as $key => $value ) {
			$redirect_url = add_query_arg( sanitize_key( (string) $key ), sanitize_text_field( (string) $value ), $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Resolve redirect URL after one-click login.
	 *
	 * @return string
	 */
	private function resolve_post_login_redirect_url() {
		$settings = Mksddn_Reddy_Auth_Settings_Page::get_runtime_settings();

		if ( ! empty( $settings['one_click_redirect_url'] ) ) {
			return esc_url_raw( (string) $settings['one_click_redirect_url'] );
		}

		if ( ! empty( $settings['login_page_id'] ) ) {
			$login_page_url = get_permalink( (int) $settings['login_page_id'] );
			if ( is_string( $login_page_url ) && '' !== $login_page_url ) {
				return remove_query_arg(
					array(
						'mksddn_reddy_status',
						'mksddn_reddy_message',
						'mksddn_reddy_intent_id',
						'mksddn_reddy_intent_secret',
					),
					$login_page_url
				);
			}
		}

		return home_url( '/' );
	}

	/**
	 * Map status to user-visible text.
	 *
	 * @param string $status Status code.
	 * @return string
	 */
	private function status_to_message( $status ) {
		$messages = array(
			'code_sent'           => __( 'OTP was sent successfully.', 'mksddn-reddy-auth' ),
			'one_click_confirmed' => __( 'Authorization confirmed. Return to the original login tab to complete sign-in.', 'mksddn-reddy-auth' ),
			'logged_in'           => __( 'Authentication successful.', 'mksddn-reddy-auth' ),
			'invalid_credentials' => __( 'Invalid credentials.', 'mksddn-reddy-auth' ),
			'auth_required'       => __( 'Please sign in with Reddy to access site content.', 'mksddn-reddy-auth' ),
			'error'               => __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ),
		);

		return isset( $messages[ $status ] ) ? $messages[ $status ] : '';
	}

	/**
	 * Map internal errors to safe user-facing text.
	 *
	 * @param WP_Error $error Error object.
	 * @return string
	 */
	private function public_message_from_error( WP_Error $error ) {
		$code = (string) $error->get_error_code();

		if ( 'rate_limited' === $code ) {
			return __( 'Too many requests. Try again later.', 'mksddn-reddy-auth' );
		}

		if ( 'reddy_id_not_allowed' === $code ) {
			return __( 'Unable to process authentication request.', 'mksddn-reddy-auth' );
		}

		return __( 'Unable to process authentication request.', 'mksddn-reddy-auth' );
	}

	/**
	 * Enqueue shortcode assets.
	 *
	 * @param bool   $enable_polling Whether intent polling should run.
	 * @param string $intent_id Intent identifier.
	 * @param string $intent_secret Intent secret.
	 * @param int    $expires_at Polling expiration timestamp.
	 * @return void
	 */
	private function enqueue_assets( $enable_polling, $intent_id, $intent_secret, $expires_at ) {
		wp_enqueue_style(
			'mksddn-reddy-auth-login-shortcode',
			plugins_url( 'assets/css/login-shortcode.css', MKSDDN_REDDY_AUTH_FILE ),
			array(),
			MKSDDN_REDDY_AUTH_VERSION
		);

		if ( ! $enable_polling ) {
			return;
		}

		wp_enqueue_script(
			'mksddn-reddy-auth-login-shortcode',
			plugins_url( 'assets/js/login-shortcode.js', MKSDDN_REDDY_AUTH_FILE ),
			array(),
			MKSDDN_REDDY_AUTH_VERSION,
			true
		);

		wp_localize_script(
			'mksddn-reddy-auth-login-shortcode',
			'mksddnReddyAuthLogin',
			array(
				'intentStatusUrl'   => rest_url( Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE . '/auth/intent-status' ),
				'completeIntentUrl' => rest_url( Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE . '/auth/complete-intent' ),
				'intentId'          => $intent_id,
				'intentSecret'      => $intent_secret,
				'pollIntervalMs'    => max( 1000, (int) apply_filters( 'mksddn_reddy_intent_poll_interval_ms', 3000 ) ),
				'maxPollIntervalMs' => max( 1000, (int) apply_filters( 'mksddn_reddy_intent_poll_max_interval_ms', 15000 ) ),
				'pollBackoffFactor' => max( 1, (int) apply_filters( 'mksddn_reddy_intent_poll_backoff_factor', 2 ) ),
				'expiresAt'         => max( 0, (int) $expires_at ),
				'redirectUrl'       => $this->resolve_post_login_redirect_url(),
				'redirectingText'   => esc_html__( 'Authorization confirmed. Redirecting...', 'mksddn-reddy-auth' ),
				'errorText'         => esc_html__( 'Unable to process authentication request.', 'mksddn-reddy-auth' ),
			)
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

		foreach ( $this->get_cookie_paths() as $cookie_path ) {
			setcookie(
				self::POLLING_COOKIE_NAME,
				$value,
				$expires_at,
				$cookie_path,
				$this->get_cookie_domain(),
				is_ssl(),
				true
			);
		}
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
	 * Clear one-click polling context cookie.
	 *
	 * @return void
	 */
	private function clear_polling_context_cookie() {
		foreach ( $this->get_cookie_paths() as $cookie_path ) {
			setcookie(
				self::POLLING_COOKIE_NAME,
				'',
				time() - HOUR_IN_SECONDS,
				$cookie_path,
				$this->get_cookie_domain(),
				is_ssl(),
				true
			);
		}
	}

	/**
	 * Persist login step context in signed HttpOnly cookie.
	 *
	 * @param string $reddy_id Reddy ID.
	 * @return void
	 */
	private function set_login_context_cookie( $reddy_id ) {
		$reddy_id = sanitize_text_field( (string) $reddy_id );
		if ( '' === $reddy_id ) {
			return;
		}

		$expires_at = time() + self::POLLING_COOKIE_TTL;
		$payload    = array(
			'reddy_id'   => $reddy_id,
			'expires_at' => $expires_at,
		);
		$encoded    = base64_encode( (string) wp_json_encode( $payload ) );
		$signature  = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		$value      = $encoded . '.' . $signature;

		foreach ( $this->get_cookie_paths() as $cookie_path ) {
			setcookie(
				self::LOGIN_CONTEXT_COOKIE_NAME,
				$value,
				$expires_at,
				$cookie_path,
				$this->get_cookie_domain(),
				is_ssl(),
				true
			);
		}
	}

	/**
	 * Read and validate Reddy ID from login context cookie.
	 *
	 * @return string
	 */
	private function get_login_reddy_id_from_cookie() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cookie access.
		if ( empty( $_COOKIE[ self::LOGIN_CONTEXT_COOKIE_NAME ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cookie access.
		$raw   = sanitize_text_field( (string) wp_unslash( $_COOKIE[ self::LOGIN_CONTEXT_COOKIE_NAME ] ) );
		$parts = explode( '.', $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$encoded   = (string) $parts[0];
		$signature = (string) $parts[1];
		$expected  = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return '';
		}

		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return '';
		}

		$payload = json_decode( $decoded, true );
		if ( ! is_array( $payload ) || empty( $payload['reddy_id'] ) || empty( $payload['expires_at'] ) ) {
			return '';
		}

		if ( time() > (int) $payload['expires_at'] ) {
			return '';
		}

		return sanitize_text_field( (string) $payload['reddy_id'] );
	}

	/**
	 * Clear login step context cookie.
	 *
	 * @return void
	 */
	private function clear_login_context_cookie() {
		foreach ( $this->get_cookie_paths() as $cookie_path ) {
			setcookie(
				self::LOGIN_CONTEXT_COOKIE_NAME,
				'',
				time() - HOUR_IN_SECONDS,
				$cookie_path,
				$this->get_cookie_domain(),
				is_ssl(),
				true
			);
		}
	}

	/**
	 * Resolve cookie paths.
	 *
	 * @return array<int, string>
	 */
	private function get_cookie_paths() {
		$paths = array();

		if ( defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ) {
			$paths[] = COOKIEPATH;
		}

		if ( defined( 'SITECOOKIEPATH' ) && is_string( SITECOOKIEPATH ) && '' !== SITECOOKIEPATH ) {
			$paths[] = SITECOOKIEPATH;
		}

		$paths[] = '/';

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Resolve cookie domain.
	 *
	 * @return string
	 */
	private function get_cookie_domain() {
		return defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
	}
}
