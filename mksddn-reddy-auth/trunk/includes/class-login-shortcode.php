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
	 * Constructor.
	 *
	 * @param Mksddn_Reddy_Auth_Otp_Service      $otp_service OTP service.
	 * @param Mksddn_Reddy_Auth_Identity_Service $identity_service Identity service.
	 * @param Mksddn_Reddy_Auth_Session_Service  $session_service Session service.
	 */
	public function __construct( Mksddn_Reddy_Auth_Otp_Service $otp_service, Mksddn_Reddy_Auth_Identity_Service $identity_service, Mksddn_Reddy_Auth_Session_Service $session_service ) {
		$this->otp_service      = $otp_service;
		$this->identity_service = $identity_service;
		$this->session_service  = $session_service;
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
	}

	/**
	 * Render login form.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		$status = isset( $_GET['mksddn_reddy_status'] ) ? sanitize_key( wp_unslash( $_GET['mksddn_reddy_status'] ) ) : '';
		$message = '';

		if ( isset( $_GET['mksddn_reddy_message'] ) ) {
			$message = sanitize_text_field( urldecode( (string) wp_unslash( $_GET['mksddn_reddy_message'] ) ) );
		}

		if ( '' === $message ) {
			$message = $this->status_to_message( $status );
		}
		$this->enqueue_assets();

		ob_start();
		?>
		<div class="mksddn-reddy-auth-form">
			<?php if ( '' !== $message ) : ?>
				<p class="mksddn-reddy-auth-message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mksddn_reddy_login_action' ); ?>
				<input type="hidden" name="action" value="mksddn_reddy_login" />
				<p>
					<label for="mksddn-reddy-id-login"><?php echo esc_html__( 'Reddy ID', 'mksddn-reddy-auth' ); ?></label><br />
					<input id="mksddn-reddy-id-login" type="text" name="reddy_id" required />
				</p>
				<p>
					<label for="mksddn-reddy-code"><?php echo esc_html__( 'One-time code', 'mksddn-reddy-auth' ); ?></label><br />
					<input id="mksddn-reddy-code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" required />
				</p>
				<p>
					<button type="submit"><?php echo esc_html__( 'Log in', 'mksddn-reddy-auth' ); ?></button>
				</p>
			</form>
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
		$result   = $this->otp_service->request_code( $reddy_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_status( 'error', $result->get_error_message() );
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

		$reddy_id = isset( $_POST['reddy_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reddy_id'] ) ) : '';
		$code     = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		$otp_result = $this->otp_service->verify_code( $reddy_id, $code );
		if ( is_wp_error( $otp_result ) ) {
			$this->redirect_with_status( 'invalid_credentials' );
		}

		$user = $this->identity_service->resolve_or_create_user( $reddy_id );
		if ( is_wp_error( $user ) ) {
			$this->redirect_with_status( 'invalid_credentials' );
		}

		$session_result = $this->session_service->login( $user );
		if ( is_wp_error( $session_result ) ) {
			$this->redirect_with_status( 'error' );
		}

		do_action( 'mksddn_reddy_after_login', $user, $reddy_id );

		$this->redirect_with_status( 'logged_in' );
	}

	/**
	 * Redirect back with status query argument.
	 *
	 * @param string $status Status key.
	 * @return void
	 */
	private function redirect_with_status( $status, $message = '' ) {
		$redirect_url = wp_get_referer();
		$redirect_url = $redirect_url ? $redirect_url : home_url( '/' );
		$redirect_url = add_query_arg( 'mksddn_reddy_status', sanitize_key( $status ), $redirect_url );
		if ( '' !== $message ) {
			$redirect_url = add_query_arg( 'mksddn_reddy_message', sanitize_text_field( $message ), $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
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
			'logged_in'           => __( 'Authentication successful.', 'mksddn-reddy-auth' ),
			'invalid_credentials' => __( 'Invalid credentials.', 'mksddn-reddy-auth' ),
			'auth_required'       => __( 'Please sign in with Reddy to access site content.', 'mksddn-reddy-auth' ),
			'error'               => __( 'Unable to process authentication request.', 'mksddn-reddy-auth' ),
		);

		return isset( $messages[ $status ] ) ? $messages[ $status ] : '';
	}

	/**
	 * Enqueue shortcode styles.
	 *
	 * @return void
	 */
	private function enqueue_assets() {
		wp_enqueue_style(
			'mksddn-reddy-auth-login-shortcode',
			plugins_url( 'assets/css/login-shortcode.css', MKSDDN_REDDY_AUTH_FILE ),
			array(),
			MKSDDN_REDDY_AUTH_VERSION
		);
	}
}
