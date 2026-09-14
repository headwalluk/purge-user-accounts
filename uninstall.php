<?php
/**
 * Remove everything this plugin owns.
 *
 * @package PurgeUserAccounts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || die();

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/functions-private.php';
require_once __DIR__ . '/includes/class-environment.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-export-directory.php';

// Orphaning a directory of email addresses would be the worst outcome here, so
// this runs before the option holding its name is deleted.
Purge_User_Accounts\Export_Directory::remove();

Purge_User_Accounts\Schema::drop_all();

$hwpua_option_names = array(
	Purge_User_Accounts\OPT_SCHEMA_VERSION,
	Purge_User_Accounts\OPT_ACTIVATED_AT,
	Purge_User_Accounts\OPT_EXPORT_DIR_NAME,
	Purge_User_Accounts\OPT_EXPORT_DIR_ERROR,
	Purge_User_Accounts\OPT_DOMAIN_ALLOWLIST,
	Purge_User_Accounts\OPT_DISABLED_RULES,
	Purge_User_Accounts\OPT_ENABLED_RULES,
	Purge_User_Accounts\OPT_CUSTOM_RULES,
	Purge_User_Accounts\OPT_PRESETS,
	Purge_User_Accounts\OPT_LAST_LOGIN_SOURCE,
	Purge_User_Accounts\OPT_FIXTURE_CREATED,
	Purge_User_Accounts\OPT_FIXTURE_COHORTS,
);

foreach ( $hwpua_option_names as $hwpua_option_name ) {
	delete_option( $hwpua_option_name );
}

wp_clear_scheduled_hook( Purge_User_Accounts\CRON_HOUSEKEEPING );

// Stashed capabilities are deliberately NOT removed: they are the only record
// needed to reverse a strip-roles quarantine, and deleting them would make an
// uninstall silently destructive.
