<?php
/**
 * Plugin bootstrap class.
 *
 * @package MksddnReddyAuth
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mksddn_Reddy_Auth_Plugin {
	/**
	 * Plugin singleton.
	 *
	 * @var Mksddn_Reddy_Auth_Plugin|null
	 */
	private static $instance = null;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mksddn-reddy-auth/v1';

	/**
	 * Reddy API client service.
	 *
	 * @var Mksddn_Reddy_Auth_Reddy_Client
	 */
	private $reddy_client;

	/**
	 * OTP service.
	 *
	 * @var Mksddn_Reddy_Auth_Otp_Service
	 */
	private $otp_service;

	/**
	 * REST auth controller.
	 *
	 * @var Mksddn_Reddy_Auth_Rest_Auth_Controller
	 */
	private $rest_controller;

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
	private $rest_auth_middleware;

	/**
	 * Request URL allowlist guard.
	 *
	 * @var Mksddn_Reddy_Auth_Request_Url_Guard
	 */
	private $request_url_guard;

	/**
	 * Settings page.
	 *
	 * @var Mksddn_Reddy_Auth_Settings_Page
	 */
	private $settings_page;

	/**
	 * Login shortcode controller.
	 *
	 * @var Mksddn_Reddy_Auth_Login_Shortcode
	 */
	private $login_shortcode;

	/**
	 * Return plugin singleton.
	 *
	 * @return Mksddn_Reddy_Auth_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->register_autoloader();
		$this->register_services();
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once MKSDDN_REDDY_AUTH_DIR . 'includes/db/class-token-repository.php';

		$token_repository = new Mksddn_Reddy_Auth_Token_Repository();
		$token_repository->create_table();

		if ( ! get_option( 'mksddn_reddy_auth_version' ) ) {
			add_option( 'mksddn_reddy_auth_version', MKSDDN_REDDY_AUTH_VERSION );
		} else {
			update_option( 'mksddn_reddy_auth_version', MKSDDN_REDDY_AUTH_VERSION );
		}

		delete_option( 'mksddn_reddy_auth_auto_create_users' );
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Reserved for cleanup that should run on plugin deactivation.
	}

	/**
	 * Run plugin hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		add_action( 'admin_menu', array( $this->settings_page, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings_page, 'register_settings' ) );
		add_action( 'admin_post_mksddn_reddy_download_openapi', array( $this->settings_page, 'download_openapi' ) );
		add_action( 'admin_post_mksddn_reddy_download_postman', array( $this->settings_page, 'download_postman_collection' ) );
		add_action( 'admin_post_mksddn_reddy_test_bot_connection', array( $this->settings_page, 'test_bot_connection' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MKSDDN_REDDY_AUTH_FILE ), array( $this, 'add_plugin_action_links' ) );
		add_filter( 'rest_pre_dispatch', array( $this->request_url_guard, 'enforce_rest_url_allowlist' ), 5, 3 );
		add_filter( 'rest_authentication_errors', array( $this->rest_auth_middleware, 'enforce_api_content_lock' ), 20 );
		add_action( 'template_redirect', array( $this->rest_auth_middleware, 'enforce_monolith_content_lock' ), 1 );
		$this->login_shortcode->register_hooks();
	}

	/**
	 * Add plugin card links in plugins list.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . Mksddn_Reddy_Auth_Settings_Page::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'mksddn-reddy-auth' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Register minimal class autoloader for plugin classes.
	 *
	 * @return void
	 */
	private function register_autoloader() {
		spl_autoload_register(
			static function ( $class_name ) {
				$prefix = 'Mksddn_Reddy_Auth_';

				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}

				$relative_class = strtolower( str_replace( '_', '-', str_replace( $prefix, '', $class_name ) ) );
				$paths          = array(
					MKSDDN_REDDY_AUTH_DIR . 'includes/class-' . $relative_class . '.php',
					MKSDDN_REDDY_AUTH_DIR . 'includes/services/class-' . $relative_class . '.php',
					MKSDDN_REDDY_AUTH_DIR . 'includes/db/class-' . $relative_class . '.php',
				);

				foreach ( $paths as $path ) {
					if ( file_exists( $path ) ) {
						require_once $path;

						return;
					}
				}
			}
		);
	}

	/**
	 * Register service instances.
	 *
	 * @return void
	 */
	private function register_services() {
		$this->reddy_client     = new Mksddn_Reddy_Auth_Reddy_Client();
		$this->otp_service      = new Mksddn_Reddy_Auth_Otp_Service( $this->reddy_client );
		$this->identity_service = new Mksddn_Reddy_Auth_Identity_Service();
		$this->session_service  = new Mksddn_Reddy_Auth_Session_Service();
		$this->token_service    = new Mksddn_Reddy_Auth_Token_Service( new Mksddn_Reddy_Auth_Token_Repository() );
		$this->rest_auth_middleware = new Mksddn_Reddy_Auth_Rest_Auth_Middleware( $this->token_service );
		$this->request_url_guard    = new Mksddn_Reddy_Auth_Request_Url_Guard();
		$this->settings_page = new Mksddn_Reddy_Auth_Settings_Page();
		$this->login_shortcode = new Mksddn_Reddy_Auth_Login_Shortcode(
			$this->otp_service,
			$this->identity_service,
			$this->session_service
		);
		$this->rest_controller  = new Mksddn_Reddy_Auth_Rest_Auth_Controller(
			$this->otp_service,
			$this->identity_service,
			$this->session_service,
			$this->token_service,
			$this->rest_auth_middleware,
			$this->request_url_guard
		);
	}
}
