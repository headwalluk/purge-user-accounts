<?php
/**
 * The Tools screen.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Registers and renders the plugin's admin screen.
 *
 * Templates receive explicit variables and use printf()/echo rather than
 * interleaving HTML with PHP, matching the house pattern.
 */
class Admin_Page {

	/**
	 * The screen hook returned by add_management_page.
	 *
	 * @var string
	 */
	protected string $screen_hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function run(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . ADMIN_POST_EXPORT, array( $this, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the page under Tools.
	 *
	 * Gated on `delete_users` rather than `manage_options`: it is the capability
	 * that actually describes what this plugin does.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->screen_hook = (string) add_management_page(
			__( 'Purge User Accounts', 'purge-user-accounts' ),
			__( 'Purge User Accounts', 'purge-user-accounts' ),
			REQUIRED_CAPABILITY,
			ADMIN_PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load styles and scripts on this screen only.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->screen_hook || $hook_suffix !== $this->screen_hook ) {
			return;
		}

		wp_enqueue_style( 'hwpua-admin', HWPUA_ASSETS_URL . 'hwpua-admin.css', array(), HWPUA_VERSION );
		wp_enqueue_script( 'hwpua-admin', HWPUA_ASSETS_URL . 'hwpua-admin.js', array(), HWPUA_VERSION, true );

		wp_localize_script(
			'hwpua-admin',
			'hwpuaAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( NONCE_ACTION ),
				'resultsUrl' => $this->get_tab_url( TAB_RESULTS ),
				'runId'      => $this->get_current_run_id(),
				'presets'    => Preset::get_all(),
				'actions'    => array(
					'start'        => AJAX_START_RUN,
					'step'         => AJAX_STEP_RUN,
					'estimate'     => AJAX_ESTIMATE,
					'preflight'    => AJAX_PREFLIGHT,
					'startJob'     => AJAX_START_JOB,
					'stepJob'      => AJAX_STEP_JOB,
					'savePreset'   => AJAX_SAVE_PRESET,
					'deletePreset' => AJAX_DELETE_PRESET,
					'importPreset' => AJAX_IMPORT_PRESET,
				),
				'strings'    => array(
					'building'      => __( 'Building…', 'purge-user-accounts' ),
					/* translators: %s: the word the operator must type, e.g. DELETE. Substituted in JavaScript. */
					'typeToConfirm' => __( 'Type %s to confirm', 'purge-user-accounts' ),
					'blocked'       => __( 'This cannot run until the checks above pass.', 'purge-user-accounts' ),
					'dryRunNote'    => __( 'Dry run — nothing was changed.', 'purge-user-accounts' ),
					'offRamp'       => __( 'Block sign-in instead', 'purge-user-accounts' ),
					'offRampNote'   => __( 'Reversible, and it stops these accounts being used at all. Delete them later, once nothing has broken.', 'purge-user-accounts' ),
					'confirmAction' => __( 'Run this action', 'purge-user-accounts' ),
					'estimating'    => __( 'Counting…', 'purge-user-accounts' ),
					'failed'        => __( 'The query stopped. Nothing was changed.', 'purge-user-accounts' ),
					'retrying'      => __( 'Connection lost — retrying…', 'purge-user-accounts' ),
					'safeToClose'   => __( 'Closing this tab stops here. Nothing already done is lost.', 'purge-user-accounts' ),
					'nameThis'      => __( 'Name this query', 'purge-user-accounts' ),
					'nothingTicked' => __( 'Tick some criteria first.', 'purge-user-accounts' ),
					'confirmForget' => __( 'Forget this saved query? Runs already made from it are unaffected.', 'purge-user-accounts' ),
					'pasteExport'   => __( 'Paste an exported query', 'purge-user-accounts' ),
				),
			)
		);
	}

	/**
	 * Generate a run's CSV and send it to the browser.
	 *
	 * POST-only with its own nonce and a capability check. The file is written
	 * with owner-only permissions, so it cannot be served as a static asset -
	 * this handler is the only way to read it, which is the intended design
	 * rather than an inconvenience.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! isset( $_POST[ NONCE_FIELD ] ) || ! check_admin_referer( NONCE_ACTION, NONCE_FIELD ) ) {
			wp_die( esc_html__( 'That link expired. Go back, reload the page and try again.', 'purge-user-accounts' ), '', array( 'response' => 403 ) );
		}

		if ( ! Environment::current_user_can_operate() ) {
			wp_die( esc_html__( 'You do not have permission to export user accounts on this site.', 'purge-user-accounts' ), '', array( 'response' => 403 ) );
		}

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$run    = Run::load( $run_id );

		if ( null === $run ) {
			wp_die( esc_html__( 'That query could not be found. It may have been pruned.', 'purge-user-accounts' ), '', array( 'response' => 404 ) );
		}

		try {
			$file_path = Csv_Export::write( $run );
		} catch ( Run_Exception $caught_error ) {
			wp_die( esc_html( $caught_error->getMessage() ), '', array( 'response' => 500 ) );
		}

		$this->send_file( $file_path, sprintf( 'purge-run-%d-%s.csv', $run->get_id(), gmdate( 'Y-m-d' ) ) );
	}

	/**
	 * Stream a file to the browser and stop.
	 *
	 * @param string $file_path     Absolute path to read.
	 * @param string $download_name Name the browser should save it as.
	 * @return void
	 */
	protected function send_file( string $file_path, string $download_name ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'Content-Length: ' . (string) filesize( $file_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a download; WP_Filesystem would read the whole file into memory.

		exit;
	}

	/**
	 * The run the Results tab is showing, if any.
	 *
	 * @return int
	 */
	protected function get_current_run_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$requested = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;

		if ( $requested > 0 ) {
			return $requested;
		}

		$latest = Run_History::get_latest_complete();

		return null === $latest ? 0 : $latest->get_id();
	}

	/**
	 * URL for one tab of this screen.
	 *
	 * @param string $tab_slug Tab identifier.
	 * @return string
	 */
	public function get_tab_url( string $tab_slug ): string {
		return add_query_arg(
			array(
				'page' => ADMIN_PAGE_SLUG,
				'tab'  => $tab_slug,
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * The tab being viewed.
	 *
	 * @return string
	 */
	protected function get_current_tab(): string {
		$known_tabs = array( TAB_BUILD, TAB_RESULTS, TAB_HISTORY, TAB_SETTINGS, TAB_HELP );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection; every state change is nonce-checked separately.
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : TAB_BUILD;

		return in_array( $requested, $known_tabs, true ) ? $requested : TAB_BUILD;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Environment::current_user_can_operate() ) {
			wp_die( esc_html__( 'You do not have permission to purge user accounts on this site.', 'purge-user-accounts' ) );
		}

		$current_tab = $this->get_current_tab();

		echo '<div class="wrap hwpua-wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Purge User Accounts', 'purge-user-accounts' ) );

		$this->render_tab_nav( $current_tab );
		$this->render_environment_notices();

		switch ( $current_tab ) {
			case TAB_RESULTS:
				$this->render_template( 'tab-results.php' );
				break;

			case TAB_HISTORY:
				$this->render_template( 'tab-history.php' );
				break;

			case TAB_SETTINGS:
				$this->render_template( 'tab-settings.php' );
				break;

			case TAB_HELP:
				$this->render_template( 'tab-help.php' );
				break;

			default:
				$this->render_template( 'tab-build.php' );
				break;
		}

		echo '</div>';
	}

	/**
	 * Render the tab strip.
	 *
	 * @param string $current_tab Tab being viewed.
	 * @return void
	 */
	protected function render_tab_nav( string $current_tab ): void {
		$tabs = array(
			TAB_BUILD    => __( 'Build a query', 'purge-user-accounts' ),
			TAB_RESULTS  => __( 'Results', 'purge-user-accounts' ),
			TAB_HISTORY  => __( 'History', 'purge-user-accounts' ),
			TAB_SETTINGS => __( 'Settings', 'purge-user-accounts' ),
			TAB_HELP     => __( 'Help', 'purge-user-accounts' ),
		);

		echo '<nav class="nav-tab-wrapper hwpua-tabs">';

		foreach ( $tabs as $tab_slug => $tab_label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $this->get_tab_url( $tab_slug ) ),
				$tab_slug === $current_tab ? ' nav-tab-active' : '',
				esc_html( $tab_label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Warn about anything the operator should know before building a query.
	 *
	 * @return void
	 */
	protected function render_environment_notices(): void {
		if ( Export_Directory::is_inside_web_root() ) {
			$this->render_notice(
				'warning',
				__( 'Exports are written inside wp-content.', 'purge-user-accounts' ),
				sprintf(
					/* translators: %s: directory path. */
					__( 'They are protected by an unguessable directory name, deny rules and file permissions, and are deleted after six hours — but a location outside the web root is safer. Define HWPUA_EXPORT_DIR to move them. Current location: %s', 'purge-user-accounts' ),
					Export_Directory::get_path()
				)
			);
		}

		if ( ! Last_Login::has_source() ) {
			$this->render_notice(
				'info',
				__( 'No last-login data is available yet.', 'purge-user-accounts' ),
				Last_Login::get_unavailable_reason()
			);
		}

		$invalid_rules = Pattern_Ruleset::get_invalid_rules();

		if ( ! empty( $invalid_rules ) ) {
			$patterns = array();

			foreach ( $invalid_rules as $invalid_rule ) {
				$patterns[] = $invalid_rule['pattern'];
			}

			$this->render_notice(
				'error',
				__( 'Some bad-signup rules could not be compiled and are inactive.', 'purge-user-accounts' ),
				implode( ' · ', $patterns )
			);
		}
	}

	/**
	 * Render one admin notice.
	 *
	 * @param string $severity One of info, warning, error.
	 * @param string $heading  Bold lead line.
	 * @param string $body     Explanation.
	 * @return void
	 */
	public function render_notice( string $severity, string $heading, string $body ): void {
		printf(
			'<div class="notice notice-%s hwpua-notice"><p><strong>%s</strong> %s</p></div>',
			esc_attr( $severity ),
			esc_html( $heading ),
			esc_html( $body )
		);
	}

	/**
	 * Include a template with the plugin in scope.
	 *
	 * @param string $template_name File name inside admin-templates/.
	 * @return void
	 */
	protected function render_template( string $template_name ): void {
		$template_path = HWPUA_ADMIN_TEMPLATES_DIR . $template_name;

		if ( ! is_readable( $template_path ) ) {
			hwpua_log_error( sprintf( 'Admin template %s is missing.', $template_name ) );
			return;
		}

		$admin_page = $this;

		require $template_path;
	}
}
