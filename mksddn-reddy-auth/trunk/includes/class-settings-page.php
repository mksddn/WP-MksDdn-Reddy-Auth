<?php
/**
 * Plugin settings page.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Settings_Page {
	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION_KEY = 'mksddn_reddy_auth_settings';

	/**
	 * Bot token option key for development fallback.
	 *
	 * @var string
	 */
	const BOT_TOKEN_OPTION_KEY = 'mksddn_reddy_auth_bot_token';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mksddn-reddy-auth';

	/**
	 * Transient key for one-time post-activation setup notice.
	 *
	 * @var string
	 */
	const SETUP_NOTICE_TRANSIENT = 'mksddn_reddy_auth_show_setup_notice';

	/**
	 * Register plugin settings page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'Reddy Auth', 'mksddn-reddy-auth' ),
			__( 'Reddy Auth', 'mksddn-reddy-auth' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'mksddn_reddy_auth_settings_group',
			self::SETTINGS_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_default_settings(),
			)
		);

		register_setting(
			'mksddn_reddy_auth_settings_group',
			self::BOT_TOKEN_OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		add_settings_section(
			'mksddn_reddy_auth_main_section',
			__( 'Authentication Settings', 'mksddn-reddy-auth' ),
			array( $this, 'render_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'bot_token',
			__( 'Bot token (dev fallback)', 'mksddn-reddy-auth' ),
			array( $this, 'render_bot_token_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section'
		);

		add_settings_field(
			'allowed_urls',
			__( 'Allowed request sources', 'mksddn-reddy-auth' ),
			array( $this, 'render_allowed_urls_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section'
		);

		add_settings_field(
			'api_lock_enabled',
			__( 'Protect all REST API content', 'mksddn-reddy-auth' ),
			array( $this, 'render_api_lock_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section'
		);

		add_settings_field(
			'monolith_lock_enabled',
			__( 'Protect site content', 'mksddn-reddy-auth' ),
			array( $this, 'render_monolith_lock_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section'
		);

		add_settings_field(
			'login_page_id',
			__( 'Login page', 'mksddn-reddy-auth' ),
			array( $this, 'render_login_page_select_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section'
		);

		add_settings_field(
			'otp_ttl_seconds',
			__( 'OTP TTL (seconds)', 'mksddn-reddy-auth' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section',
			array(
				'key'  => 'otp_ttl_seconds',
				'min'  => 60,
				'max'  => 900,
				'step' => 1,
			)
		);

		add_settings_field(
			'send_rate_limit',
			__( 'Send-code limit (per 5 minutes)', 'mksddn-reddy-auth' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section',
			array(
				'key'         => 'send_rate_limit',
				'min'         => 1,
				'max'         => 20,
				'step'        => 1,
				'description' => __( 'Max OTP send requests per Reddy ID and client IP within a fixed 5-minute window.', 'mksddn-reddy-auth' ),
			)
		);

		add_settings_field(
			'login_rate_limit',
			__( 'Login attempt limit (per 10 minutes)', 'mksddn-reddy-auth' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section',
			array(
				'key'         => 'login_rate_limit',
				'min'         => 1,
				'max'         => 30,
				'step'        => 1,
				'description' => __( 'Max login attempts per Reddy ID and client IP within a fixed 10-minute window.', 'mksddn-reddy-auth' ),
			)
		);

		add_settings_field(
			'token_ttl_seconds',
			__( 'Bearer token TTL (seconds)', 'mksddn-reddy-auth' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_main_section',
			array(
				'key'  => 'token_ttl_seconds',
				'min'  => 3600,
				'max'  => 7776000,
				'step' => 60,
			)
		);

		add_settings_section(
			'mksddn_reddy_auth_dev_section',
			__( 'Developer Resources', 'mksddn-reddy-auth' ),
			'__return_empty_string',
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_contracts',
			__( 'API Contracts', 'mksddn-reddy-auth' ),
			array( $this, 'render_api_contracts_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_dev_section'
		);

		add_settings_field(
			'bot_connection_test',
			__( 'Bot connection test', 'mksddn-reddy-auth' ),
			array( $this, 'render_bot_connection_test_field' ),
			self::PAGE_SLUG,
			'mksddn_reddy_auth_dev_section'
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Reddy Auth Settings', 'mksddn-reddy-auth' ); ?></h1>
			<div class="notice notice-info inline" style="padding:12px 16px;">
				<p style="margin:0 0 8px;">
					<strong><?php echo esc_html__( 'Quick Start', 'mksddn-reddy-auth' ); ?></strong>
				</p>
				<p style="margin:0 0 8px;">
					<?php echo esc_html__( '1) Set bot token (prefer MKSDDN_REDDY_BOT_TOKEN in wp-config.php for production). 2) Configure security limits below. 3) Create a login page and add shortcode:', 'mksddn-reddy-auth' ); ?>
					<code>[mksddn_reddy_login]</code>.
				</p>
				<p style="margin:0 0 8px;">
					<?php echo esc_html__( '4) If site protection is enabled, select Login page to avoid redirect loops.', 'mksddn-reddy-auth' ); ?>
				</p>
				<p style="margin:0;">
					<?php echo esc_html__( 'Site and REST protection are off by default. Enable them only after the login page is ready.', 'mksddn-reddy-auth' ); ?>
				</p>
			</div>
			<?php $this->render_bot_test_notice(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'mksddn_reddy_auth_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mksddn-reddy-bot-test-form">
				<input type="hidden" name="action" value="mksddn_reddy_test_bot_connection" />
				<?php wp_nonce_field( 'mksddn_reddy_test_bot_connection' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render section description.
	 *
	 * @return void
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Use wp-config constant MKSDDN_REDDY_BOT_TOKEN in production.', 'mksddn-reddy-auth' ) . '</p>';
	}

	/**
	 * Show dismissible setup notice after plugin activation.
	 *
	 * @return void
	 */
	public function maybe_render_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- dismiss action verified below.
		if ( isset( $_GET['mksddn_reddy_dismiss_setup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['mksddn_reddy_dismiss_setup'] ) ) ) {
			check_admin_referer( 'mksddn_reddy_dismiss_setup' );
			delete_transient( self::SETUP_NOTICE_TRANSIENT );
			wp_safe_redirect( remove_query_arg( array( 'mksddn_reddy_dismiss_setup', '_wpnonce' ) ) );
			exit;
		}

		if ( ! get_transient( self::SETUP_NOTICE_TRANSIENT ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'mksddn_reddy_dismiss_setup', '1' ),
			'mksddn_reddy_dismiss_setup'
		);
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php echo esc_html__( 'Reddy Auth is active.', 'mksddn-reddy-auth' ); ?></strong>
				<?php
				echo esc_html__(
					'Configure your bot token, create a login page with [mksddn_reddy_login], then optionally enable site or REST protection.',
					'mksddn-reddy-auth'
				);
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php echo esc_html__( 'Open settings', 'mksddn-reddy-auth' ); ?></a>
				| <a href="<?php echo esc_url( $dismiss_url ); ?>"><?php echo esc_html__( 'Dismiss', 'mksddn-reddy-auth' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render bot token input.
	 *
	 * @return void
	 */
	public function render_bot_token_field() {
		$value = (string) get_option( self::BOT_TOKEN_OPTION_KEY, '' );
		?>
		<input type="password" name="<?php echo esc_attr( self::BOT_TOKEN_OPTION_KEY ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="off" />
		<?php
	}

	/**
	 * Render allowed request source URLs field.
	 *
	 * @return void
	 */
	public function render_allowed_urls_field() {
		$settings = $this->get_settings();
		$allowed  = isset( $settings['allowed_urls'] ) && is_array( $settings['allowed_urls'] ) ? $settings['allowed_urls'] : array();
		$value    = implode( "\n", $allowed );
		?>
		<textarea
			name="<?php echo esc_attr( self::SETTINGS_OPTION_KEY . '[allowed_urls]' ); ?>"
			rows="5"
			cols="50"
			class="large-text code"
			placeholder="https://app.example.com&#10;https://admin.example.com/panel"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			<?php echo esc_html__( 'One URL per line (scheme + host, optional path prefix). Empty = no restriction. Applies only to plugin REST routes (/mksddn-reddy-auth/v1/*), not the login shortcode.', 'mksddn-reddy-auth' ); ?>
		</p>
		<p class="description">
			<?php echo esc_html__( 'Soft guard for browser apps: checks Origin or Referer, not a secret key. Headers can be spoofed—rely on OTP, rate limits, and API lock for real protection. Server clients (curl, Postman, backends) usually need an empty list or the mksddn_reddy_is_request_url_allowed filter.', 'mksddn-reddy-auth' ); ?>
		</p>
		<?php
	}

	/**
	 * Render API lock checkbox.
	 *
	 * @return void
	 */
	public function render_api_lock_field() {
		$settings = $this->get_settings();
		$checked  = ! empty( $settings['api_lock_enabled'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::SETTINGS_OPTION_KEY . '[api_lock_enabled]' ); ?>" value="1" <?php checked( $checked ); ?> />
			<?php echo esc_html__( 'Require Reddy authentication for all REST API content (except send-code/login).', 'mksddn-reddy-auth' ); ?>
		</label>
		<?php
	}

	/**
	 * Render monolith content lock checkbox.
	 *
	 * @return void
	 */
	public function render_monolith_lock_field() {
		$settings = $this->get_settings();
		$checked  = ! empty( $settings['monolith_lock_enabled'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::SETTINGS_OPTION_KEY . '[monolith_lock_enabled]' ); ?>" value="1" <?php checked( $checked ); ?> />
			<?php echo esc_html__( 'Require Reddy authentication for frontend site content.', 'mksddn-reddy-auth' ); ?>
		</label>
		<?php
	}

	/**
	 * Render login page select field.
	 *
	 * @return void
	 */
	public function render_login_page_select_field() {
		$settings = $this->get_settings();
		$selected = isset( $settings['login_page_id'] ) ? (int) $settings['login_page_id'] : 0;
		?>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages escapes output internally.
		wp_dropdown_pages(
			array(
				'name'              => self::SETTINGS_OPTION_KEY . '[login_page_id]',
				'selected'          => $selected,
				'show_option_none'  => __( '-- Select page --', 'mksddn-reddy-auth' ),
				'option_none_value' => '0',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<p class="description">
			<?php echo esc_html__( 'Select page where [mksddn_reddy_login] shortcode is placed.', 'mksddn-reddy-auth' ); ?>
		</p>
		<?php
	}

	/**
	 * Render numeric setting field.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_number_field( $args ) {
		$settings = $this->get_settings();
		$key      = isset( $args['key'] ) ? (string) $args['key'] : '';
		$min      = isset( $args['min'] ) ? (int) $args['min'] : 0;
		$max      = isset( $args['max'] ) ? (int) $args['max'] : 999999;
		$step     = isset( $args['step'] ) ? (int) $args['step'] : 1;
		$value    = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;
		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::SETTINGS_OPTION_KEY . '[' . $key . ']' ); ?>"
			value="<?php echo esc_attr( (string) $value ); ?>"
			min="<?php echo esc_attr( (string) $min ); ?>"
			max="<?php echo esc_attr( (string) $max ); ?>"
			step="<?php echo esc_attr( (string) $step ); ?>"
			class="small-text"
		/>
		<?php if ( ! empty( $args['description'] ) ) : ?>
		<p class="description">
			<?php echo esc_html( (string) $args['description'] ); ?>
		</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render API download actions.
	 *
	 * @return void
	 */
	public function render_api_contracts_field() {
		$openapi_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mksddn_reddy_download_openapi' ),
			'mksddn_reddy_download_openapi'
		);
		$postman_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mksddn_reddy_download_postman' ),
			'mksddn_reddy_download_postman'
		);
		?>
		<a class="button button-secondary" href="<?php echo esc_url( $openapi_url ); ?>">
			<?php echo esc_html__( 'Download OpenAPI', 'mksddn-reddy-auth' ); ?>
		</a>
		&nbsp;
		<a class="button button-secondary" href="<?php echo esc_url( $postman_url ); ?>">
			<?php echo esc_html__( 'Download Postman collection', 'mksddn-reddy-auth' ); ?>
		</a>
		<p class="description">
			<?php echo esc_html__( 'Export current REST contracts for API clients and tests.', 'mksddn-reddy-auth' ); ?>
		</p>
		<?php
	}

	/**
	 * Render test bot connection action.
	 *
	 * @return void
	 */
	public function render_bot_connection_test_field() {
		?>
		<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
			<input
				type="text"
				name="test_reddy_id"
				form="mksddn-reddy-bot-test-form"
				placeholder="<?php echo esc_attr__( 'Reddy ID', 'mksddn-reddy-auth' ); ?>"
				required
			/>
			<button type="submit" form="mksddn-reddy-bot-test-form" class="button button-secondary">
				<?php echo esc_html__( 'Test bot connection', 'mksddn-reddy-auth' ); ?>
			</button>
		</div>
		<p class="description">
			<?php echo esc_html__( 'Sends a test message to the specified Reddy user.', 'mksddn-reddy-auth' ); ?>
		</p>
		<?php
	}

	/**
	 * Download OpenAPI document.
	 *
	 * @return void
	 */
	public function download_openapi() {
		$this->assert_download_permissions( 'mksddn_reddy_download_openapi' );
		$document = $this->build_openapi_document();
		$this->output_json_download( 'mksddn-reddy-auth-openapi.json', $document );
	}

	/**
	 * Download Postman collection.
	 *
	 * @return void
	 */
	public function download_postman_collection() {
		$this->assert_download_permissions( 'mksddn_reddy_download_postman' );
		$collection = $this->build_postman_collection();
		$this->output_json_download( 'mksddn-reddy-auth-postman.json', $collection );
	}

	/**
	 * Run bot connection test from admin settings page.
	 *
	 * @return void
	 */
	public function test_bot_connection() {
		$this->assert_download_permissions( 'mksddn_reddy_test_bot_connection' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assert_download_permissions().
		$reddy_id = isset( $_POST['test_reddy_id'] ) ? sanitize_text_field( wp_unslash( $_POST['test_reddy_id'] ) ) : '';
		$client   = new Mksddn_Reddy_Auth_Reddy_Client();
		$result   = $client->test_connection( $reddy_id );
		$status   = 'success';
		$message  = __( 'Bot connection test message sent successfully.', 'mksddn-reddy-auth' );

		if ( is_wp_error( $result ) ) {
			$status  = 'error';
			$message = $result->get_error_message();
		}

		$redirect_url = add_query_arg(
			array(
				'page'                  => self::PAGE_SLUG,
				'mksddn_reddy_bot_test' => $status,
				'mksddn_reddy_bot_msg'  => rawurlencode( sanitize_text_field( $message ) ),
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Sanitize settings array.
	 *
	 * @param mixed $raw Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = $this->get_default_settings();

		$sanitized = array(
			'allowed_urls'          => Mksddn_Reddy_Auth_Request_Url_Guard::sanitize_allowed_urls( isset( $raw['allowed_urls'] ) ? $raw['allowed_urls'] : '' ),
			'api_lock_enabled'      => ! empty( $raw['api_lock_enabled'] ) ? 1 : 0,
			'monolith_lock_enabled' => ! empty( $raw['monolith_lock_enabled'] ) ? 1 : 0,
			'login_page_id'         => isset( $raw['login_page_id'] ) ? absint( $raw['login_page_id'] ) : 0,
			'login_page_url'        => isset( $raw['login_page_url'] ) ? esc_url_raw( (string) $raw['login_page_url'] ) : '',
			'otp_ttl_seconds'       => $this->sanitize_int_range( $raw, 'otp_ttl_seconds', $defaults['otp_ttl_seconds'], 60, 900 ),
			'send_rate_limit'       => $this->sanitize_int_range( $raw, 'send_rate_limit', $defaults['send_rate_limit'], 1, 20 ),
			'login_rate_limit'      => $this->sanitize_int_range( $raw, 'login_rate_limit', $defaults['login_rate_limit'], 1, 30 ),
			'token_ttl_seconds'     => $this->sanitize_int_range( $raw, 'token_ttl_seconds', $defaults['token_ttl_seconds'], 3600, 7776000 ),
		);

		return $sanitized;
	}

	/**
	 * Sanitize boolean values from form.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_bool( $value ) {
		return ! empty( $value );
	}

	/**
	 * Return merged settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings() {
		$raw = get_option( self::SETTINGS_OPTION_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();

		return wp_parse_args( $raw, $this->get_default_settings() );
	}

	/**
	 * Default settings used on first install and as merge fallback.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_install_defaults() {
		return array(
			'allowed_urls'          => array(),
			'api_lock_enabled'      => 0,
			'monolith_lock_enabled' => 0,
			'login_page_id'         => 0,
			'login_page_url'        => '',
			'otp_ttl_seconds'       => 300,
			'send_rate_limit'       => 5,
			'login_rate_limit'      => 7,
			'token_ttl_seconds'     => 2592000,
		);
	}

	/**
	 * Get defaults for plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_default_settings() {
		return self::get_install_defaults();
	}

	/**
	 * Sanitize and clamp integer option.
	 *
	 * @param array<string, mixed> $raw Raw settings array.
	 * @param string               $key Setting key.
	 * @param int                  $default Default value.
	 * @param int                  $min Min value.
	 * @param int                  $max Max value.
	 * @return int
	 */
	private function sanitize_int_range( array $raw, $key, $default, $min, $max ) {
		if ( ! isset( $raw[ $key ] ) ) {
			return (int) $default;
		}

		$value = (int) $raw[ $key ];
		$value = max( (int) $min, $value );
		$value = min( (int) $max, $value );

		return $value;
	}

	/**
	 * Validate permissions and nonce for download actions.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function assert_download_permissions( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mksddn-reddy-auth' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Render bot test status message after redirect.
	 *
	 * @return void
	 */
	private function render_bot_test_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag after redirect.
		$status = isset( $_GET['mksddn_reddy_bot_test'] ) ? sanitize_key( wp_unslash( $_GET['mksddn_reddy_bot_test'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only notice after redirect; sanitized on assignment.
		$msg = isset( $_GET['mksddn_reddy_bot_msg'] ) ? sanitize_text_field( urldecode( (string) wp_unslash( $_GET['mksddn_reddy_bot_msg'] ) ) ) : '';

		if ( '' === $status || '' === $msg ) {
			return;
		}

		$notice_class = 'success' === $status ? 'notice-success' : 'notice-error';
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<p><?php echo esc_html( $msg ); ?></p>
		</div>
		<?php
	}

	/**
	 * Output JSON as file download.
	 *
	 * @param string $filename Filename.
	 * @param array  $payload JSON payload.
	 * @return void
	 */
	private function output_json_download( $filename, array $payload ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Build OpenAPI 3.0 document.
	 *
	 * @return array<string, mixed>
	 */
	private function build_openapi_document() {
		return array(
			'openapi'    => '3.0.3',
			'info'       => array(
				'title'       => 'MksDdn Reddy Auth API',
				'version'     => MKSDDN_REDDY_AUTH_VERSION,
				'description' => 'REST API for OTP authentication and token/session flows. REST login sets a WordPress cookie only when issue_session is true (default false). Use issue_token for Bearer auth. When Allowed request sources is configured in settings, auth routes may return 403 if Origin/Referer does not match (browser-source soft guard; not a substitute for OTP or Bearer auth).',
			),
			'servers'    => array(
				array(
					'url' => rest_url( Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE ),
				),
			),
			'paths'      => array(
				'/auth/send-code' => array(
					'post' => array(
						'summary'     => 'Send OTP code',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'reddy_id' ),
										'properties' => array(
											'reddy_id' => array( 'type' => 'string' ),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array( 'description' => 'OTP request accepted' ),
							'400' => array( 'description' => 'Validation or auth flow error' ),
							'403' => array( 'description' => 'Request source not allowed (allowed_urls setting)' ),
						),
					),
				),
				'/auth/login'     => array(
					'post' => array(
						'summary'     => 'Login by OTP',
						'description' => 'Verify OTP and resolve the WordPress user. issue_token returns a Bearer token. issue_session sets the WordPress auth cookie (default false). Shortcode login always sets a cookie.',
						'requestBody' => array(
							'required' => true,
							'content'  => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'required'   => array( 'reddy_id', 'code' ),
										'properties' => array(
											'reddy_id'      => array(
												'type'        => 'string',
												'description' => 'Reddy user identifier.',
											),
											'code'          => array(
												'type'        => 'string',
												'description' => 'Six-digit OTP from Reddy.',
											),
											'issue_token'   => array(
												'type'        => 'boolean',
												'default'     => false,
												'description' => 'Return a Bearer access token for REST API clients.',
											),
											'issue_session' => array(
												'type'        => 'boolean',
												'default'     => false,
												'description' => 'Set the WordPress auth cookie. Required for Protect site content in the browser. Default false on REST login.',
											),
										),
									),
								),
							),
						),
						'responses'   => array(
							'200' => array( 'description' => 'Authenticated' ),
							'400' => array( 'description' => 'Invalid credentials' ),
							'403' => array( 'description' => 'Request source not allowed (allowed_urls setting)' ),
							'500' => array( 'description' => 'Session or token issue error' ),
						),
					),
				),
				'/auth/me'        => array(
					'get' => array(
						'summary'     => 'Get current user',
						'description' => 'Accepts Bearer token or WordPress cookie session (shortcode login or REST login with issue_session: true).',
						'security'  => array(
							array( 'bearerAuth' => array() ),
							array( 'cookieAuth' => array() ),
						),
						'responses' => array(
							'200' => array( 'description' => 'Current user' ),
							'401' => array( 'description' => 'Unauthenticated' ),
						),
					),
				),
				'/auth/logout'    => array(
					'post' => array(
						'summary'     => 'Logout',
						'description' => 'Destroys the WordPress cookie session and revokes the Bearer token when sent in Authorization.',
						'security'  => array(
							array( 'bearerAuth' => array() ),
							array( 'cookieAuth' => array() ),
						),
						'responses' => array(
							'200' => array( 'description' => 'Logged out' ),
						),
					),
				),
			),
			'components' => array(
				'securitySchemes' => array(
					'bearerAuth' => array(
						'type'   => 'http',
						'scheme' => 'bearer',
					),
					'cookieAuth' => array(
						'type' => 'apiKey',
						'in'   => 'cookie',
						'name' => 'wordpress_logged_in_*',
					),
				),
			),
		);
	}

	/**
	 * Build Postman collection.
	 *
	 * @return array<string, mixed>
	 */
	private function build_postman_collection() {
		$base_url = untrailingslashit( rest_url( Mksddn_Reddy_Auth_Plugin::REST_NAMESPACE ) );

		return array(
			'info'     => array(
				'_postman_id' => wp_generate_uuid4(),
				'name'        => 'MksDdn Reddy Auth',
				'description' => 'REST login does not set a WordPress cookie unless issue_session is true. Use issue_token for Bearer auth (headless). Protect site content requires a cookie (shortcode or issue_session: true). Protect REST API content requires Bearer. If Allowed request sources is set in WP settings, add an Origin header matching a listed URL or leave the allowlist empty for server-side clients.',
				'schema'      => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
			),
			'variable' => array(
				array(
					'key'   => 'baseUrl',
					'value' => $base_url,
				),
				array(
					'key'   => 'bearerToken',
					'value' => '',
				),
			),
			'item'     => array(
				$this->build_postman_item( 'Send code', 'POST', '{{baseUrl}}/auth/send-code', array( 'reddy_id' => '123456' ) ),
				$this->build_postman_item(
					'Login (Bearer only)',
					'POST',
					'{{baseUrl}}/auth/login',
					array(
						'reddy_id'      => '123456',
						'code'          => '111111',
						'issue_token'   => true,
						'issue_session' => false,
					)
				),
				$this->build_postman_item(
					'Login (Bearer + cookie)',
					'POST',
					'{{baseUrl}}/auth/login',
					array(
						'reddy_id'      => '123456',
						'code'          => '111111',
						'issue_token'   => true,
						'issue_session' => true,
					)
				),
				$this->build_postman_item( 'Me', 'GET', '{{baseUrl}}/auth/me', null, true ),
				$this->build_postman_item( 'Logout', 'POST', '{{baseUrl}}/auth/logout', null, true ),
			),
		);
	}

	/**
	 * Build one Postman request item.
	 *
	 * @param string     $name Item title.
	 * @param string     $method HTTP method.
	 * @param string     $url URL with variables.
	 * @param array|null $body JSON body.
	 * @param bool       $with_bearer Include bearer token header.
	 * @return array<string, mixed>
	 */
	private function build_postman_item( $name, $method, $url, $body = null, $with_bearer = false ) {
		$headers = array(
			array(
				'key'   => 'Content-Type',
				'value' => 'application/json',
			),
		);

		if ( $with_bearer ) {
			$headers[] = array(
				'key'   => 'Authorization',
				'value' => 'Bearer {{bearerToken}}',
			);
		}

		$request = array(
			'method' => $method,
			'header' => $headers,
			'url'    => $url,
		);

		if ( is_array( $body ) ) {
			$request['body'] = array(
				'mode' => 'raw',
				'raw'  => wp_json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			);
		}

		return array(
			'name'    => $name,
			'request' => $request,
		);
	}
}
