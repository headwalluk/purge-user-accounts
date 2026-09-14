<?php
/**
 * Plugin Name:       Purge User Accounts
 * Plugin URI:        https://headwall-hosting.com/
 * Description:       Find, audit and remove junk user accounts at scale — botnet signups, sleeper accounts and dormant subscribers.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Paul Faulkner
 * Author URI:        https://headwall-hosting.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       purge-user-accounts
 *
 * @package PurgeUserAccounts
 */

defined( 'ABSPATH' ) || die();

const HWPUA_NAME    = 'purge-user-accounts';
const HWPUA_VERSION = '0.1.0';

define( 'HWPUA_FILE', __FILE__ );
define( 'HWPUA_DIR', plugin_dir_path( __FILE__ ) );
define( 'HWPUA_URL', plugin_dir_url( __FILE__ ) );
define( 'HWPUA_ADMIN_TEMPLATES_DIR', trailingslashit( HWPUA_DIR . 'admin-templates' ) );
define( 'HWPUA_ASSETS_URL', trailingslashit( HWPUA_URL . 'assets' ) );
define( 'HWPUA_DATA_DIR', trailingslashit( HWPUA_DIR . 'data' ) );

// Load constants and helpers.
require_once HWPUA_DIR . 'constants.php';
require_once HWPUA_DIR . 'functions-private.php';

// Load plugin classes.
require_once HWPUA_DIR . 'includes/class-environment.php';
require_once HWPUA_DIR . 'includes/class-settings.php';
require_once HWPUA_DIR . 'includes/class-schema.php';
require_once HWPUA_DIR . 'includes/class-export-directory.php';

// Engine. Load order matters: Filter and Integration are extended by the
// integrations, which the registry then collects.
require_once HWPUA_DIR . 'includes/class-run-exception.php';
require_once HWPUA_DIR . 'includes/class-filter.php';
require_once HWPUA_DIR . 'includes/class-last-login-source.php';
require_once HWPUA_DIR . 'includes/class-integration.php';
require_once HWPUA_DIR . 'includes/class-integration-registry.php';
require_once HWPUA_DIR . 'includes/class-signin-block.php';
require_once HWPUA_DIR . 'includes/class-guards.php';
require_once HWPUA_DIR . 'includes/class-stage.php';
require_once HWPUA_DIR . 'includes/class-run.php';
require_once HWPUA_DIR . 'includes/class-run-builder.php';
require_once HWPUA_DIR . 'includes/class-stepper.php';
require_once HWPUA_DIR . 'includes/class-action.php';
require_once HWPUA_DIR . 'includes/class-action-result.php';
require_once HWPUA_DIR . 'includes/class-action-registry.php';
require_once HWPUA_DIR . 'includes/class-job.php';
require_once HWPUA_DIR . 'includes/class-job-runner.php';
require_once HWPUA_DIR . 'includes/class-preflight.php';
require_once HWPUA_DIR . 'includes/class-preset.php';
require_once HWPUA_DIR . 'includes/class-pattern-ruleset.php';
require_once HWPUA_DIR . 'includes/class-domain-allowlist.php';
require_once HWPUA_DIR . 'includes/class-last-login.php';

// Integrations. Explicit over glob: load order is visible, and a stray file
// cannot quietly become active code.
require_once HWPUA_DIR . 'integrations/actions-wp-core.php';
require_once HWPUA_DIR . 'integrations/integration-wp-core.php';
require_once HWPUA_DIR . 'integrations/integration-woocommerce.php';

require_once HWPUA_DIR . 'includes/class-fixture.php';
require_once HWPUA_DIR . 'includes/class-benchmark.php';
require_once HWPUA_DIR . 'includes/class-cli.php';
require_once HWPUA_DIR . 'includes/class-cli-operator.php';
require_once HWPUA_DIR . 'includes/class-csv-export.php';
require_once HWPUA_DIR . 'includes/class-run-history.php';
require_once HWPUA_DIR . 'includes/class-users-list-table.php';
require_once HWPUA_DIR . 'includes/class-admin-page.php';
require_once HWPUA_DIR . 'includes/class-ajax.php';

require_once HWPUA_DIR . 'includes/class-plugin.php';

/**
 * Create the plugin's tables and export directory on activation.
 */
function hwpua_activate() {
	if ( Purge_User_Accounts\Environment::is_supported() ) {
		Purge_User_Accounts\Schema::install();

		// Activation often runs as a different OS user than PHP-FPM - WP-CLI
		// especially - so this can legitimately fail here and succeed later.
		// Record it either way; Plugin::run() retries on the next admin request.
		Purge_User_Accounts\Export_Directory::record_provision_result();

		update_option( Purge_User_Accounts\OPT_ACTIVATED_AT, gmdate( 'Y-m-d H:i:s' ), false );
	}
}
register_activation_hook( __FILE__, 'hwpua_activate' );

/**
 * Clear scheduled events on deactivation. Tables and settings are retained.
 */
function hwpua_deactivate() {
	wp_clear_scheduled_hook( Purge_User_Accounts\CRON_HOUSEKEEPING );
}
register_deactivation_hook( __FILE__, 'hwpua_deactivate' );

/**
 * Launch the plugin core.
 */
function hwpua_plugin_run() {
	global $hwpua_plugin;

	$hwpua_plugin = new Purge_User_Accounts\Plugin();
	$hwpua_plugin->run();
}
hwpua_plugin_run();
