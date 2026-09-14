<?php
/**
 * Top-level orchestrator.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Wires the plugin together.
 *
 * Every hook this plugin registers is registered here and implemented in the
 * class that owns the behaviour.
 */
class Plugin {

	/**
	 * Type-safe option access, shared by components.
	 *
	 * @var Settings|null
	 */
	protected ?Settings $settings = null;

	/**
	 * Register hooks, or explain why the plugin is inert.
	 */
	public function run(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );

		if ( ! Environment::is_supported() ) {
			add_action( 'admin_notices', array( $this, 'render_unsupported_notice' ) );
			return;
		}

		// Enforcement first: a blocked account must be refused on every request,
		// not only on the screens this plugin owns.
		$signin_block = new Signin_Block();
		$signin_block->run();

		Cli::register();
		Cli_Operator::register();

		$admin_page = new Admin_Page();
		$admin_page->run();

		$ajax = new Ajax();
		$ajax->run();

		add_action( 'admin_init', array( $this, 'maybe_upgrade_schema' ), 1 );
		add_action( 'admin_init', array( $this, 'ensure_export_directory' ), 5 );
		add_action( 'admin_init', array( $this, 'purge_expired_exports' ), 20 );
		add_action( 'admin_notices', array( $this, 'render_export_directory_notice' ) );

		// Records logins from activation onward. Unconditional: an operator who
		// turns this off and returns in six months has destroyed the only
		// trustworthy last-login source on the site.
		add_action( 'wp_login', array( $this, 'record_last_login' ), 10, 2 );

		add_action( CRON_HOUSEKEEPING, array( $this, 'run_housekeeping' ) );
		add_action( 'init', array( $this, 'schedule_housekeeping' ) );
	}

	/**
	 * Declare compatibility with WooCommerce's HPOS order tables.
	 */
	public function declare_woocommerce_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HWPUA_FILE, true );
		}
	}

	/**
	 * Shared settings instance.
	 */
	public function get_settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}

	/**
	 * Explain in admin why the plugin is doing nothing.
	 */
	public function render_unsupported_notice(): void {
		$reason = Environment::get_unsupported_reason();

		if ( '' !== $reason && current_user_can( 'activate_plugins' ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Purge User Accounts is inactive.', 'purge-user-accounts' ),
				esc_html( $reason )
			);
		}
	}

	/**
	 * Install or update tables when the code is ahead of the database.
	 */
	public function maybe_upgrade_schema(): void {
		Schema::maybe_upgrade();
	}

	/**
	 * Record the time of a successful login, for every user.
	 *
	 * @param string   $user_login Login name of the authenticated user.
	 * @param \WP_User $user       The authenticated user.
	 * @return void
	 */
	public function record_last_login( string $user_login, \WP_User $user ): void {
		update_user_meta( $user->ID, META_LAST_LOGIN, hwpua_now() );
	}

	/**
	 * Ensure the daily housekeeping event is scheduled.
	 */
	public function schedule_housekeeping(): void {
		if ( ! wp_next_scheduled( CRON_HOUSEKEEPING ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CRON_HOUSEKEEPING );
		}
	}

	/**
	 * Retry export directory provisioning under the web server's user.
	 *
	 * @return void
	 */
	public function ensure_export_directory(): void {
		if ( Environment::current_user_can_operate() ) {
			Export_Directory::ensure_ready();
		}
	}

	/**
	 * Surface a provisioning failure rather than discovering it at export time.
	 *
	 * @return void
	 */
	public function render_export_directory_notice(): void {
		$settings = $this->get_settings();
		$failure  = $settings->get_string( OPT_EXPORT_DIR_ERROR );

		if ( '' !== $failure && Environment::current_user_can_operate() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Purge User Accounts:', 'purge-user-accounts' ),
				esc_html( $failure )
			);
		}
	}

	/**
	 * Prune expired runs, jobs and export files.
	 */
	public function run_housekeeping(): void {
		Export_Directory::purge_expired();
		Run_History::prune_expired();
	}

	/**
	 * Purge expired exports opportunistically, on top of the cron event.
	 *
	 * WP-Cron fires on inbound traffic, and the sites that accumulate bot
	 * accounts are the quiet ones. Personal data must not sit on disk waiting
	 * for a visitor, so the admin screen sweeps too.
	 */
	public function purge_expired_exports(): void {
		if ( Environment::current_user_can_operate() ) {
			Export_Directory::purge_expired();
		}
	}
}
