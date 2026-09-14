<?php
/**
 * Custom table definitions and installation.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Owns the plugin's five custom tables.
 *
 * Schema rationale is in dev-notes/03-query-engine.md §2. In short: a run is a
 * materialised, persisted result set rather than a query re-executed per page,
 * which is what makes paging stable, export streamable and deletion resumable.
 */
class Schema {

	/**
	 * Bump when any CREATE TABLE below changes.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Prefix an unprefixed table constant with the site's table prefix.
	 *
	 * @param string $table_name Unprefixed table name.
	 * @return string
	 */
	public static function table( string $table_name ): string {
		global $wpdb;

		return $wpdb->prefix . $table_name;
	}

	/**
	 * Create or update every table, then record the schema version.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::get_table_definitions() as $create_statement ) {
			dbDelta( $create_statement );
		}

		update_option( OPT_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Run the installer when the stored schema version is behind the code.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( OPT_SCHEMA_VERSION ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/**
	 * Whether every expected table is present.
	 */
	public static function is_installed(): bool {
		global $wpdb;

		$all_present = true;

		foreach ( self::get_table_names() as $table_name ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

			if ( $found !== $table_name ) {
				$all_present = false;
				break;
			}
		}

		return $all_present;
	}

	/**
	 * Every table this plugin owns, fully prefixed.
	 *
	 * @return string[]
	 */
	public static function get_table_names(): array {
		return array(
			self::table( TABLE_RUNS ),
			self::table( TABLE_RUN_ITEMS ),
			self::table( TABLE_RUN_SCRATCH ),
			self::table( TABLE_JOBS ),
			self::table( TABLE_JOB_ITEMS ),
		);
	}

	/**
	 * Drop every table. Called from uninstall.php only.
	 */
	public static function drop_all(): void {
		global $wpdb;

		foreach ( self::get_table_names() as $table_name ) {
			// Table names come from our own constants, never from input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * The CREATE TABLE statements, formatted for dbDelta.
	 *
	 * Note dbDelta is fussy: two spaces after PRIMARY KEY, KEY rather than
	 * INDEX, one field per line, and lowercase types.
	 *
	 * @return string[]
	 */
	protected static function get_table_definitions(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$runs          = self::table( TABLE_RUNS );
		$definitions[] = "CREATE TABLE {$runs} (
			run_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			filter_spec longtext NOT NULL,
			spec_hash char(64) NOT NULL,
			preset_name varchar(191) DEFAULT NULL,
			status varchar(20) NOT NULL,
			stage_index smallint(5) unsigned NOT NULL DEFAULT 0,
			stage_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			max_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			candidate_count bigint(20) unsigned NOT NULL DEFAULT 0,
			matched_count bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			failure_reason text DEFAULT NULL,
			PRIMARY KEY  (run_id),
			KEY status_created (status,created_at),
			KEY spec_hash (spec_hash)
		) {$charset_collate};";

		$run_items     = self::table( TABLE_RUN_ITEMS );
		$definitions[] = "CREATE TABLE {$run_items} (
			run_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			matched_rule varchar(255) DEFAULT NULL,
			PRIMARY KEY  (run_id,user_id),
			KEY run_rule (run_id,matched_rule)
		) {$charset_collate};";

		$run_scratch   = self::table( TABLE_RUN_SCRATCH );
		$definitions[] = "CREATE TABLE {$run_scratch} (
			run_id bigint(20) unsigned NOT NULL,
			bucket varchar(32) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (run_id,bucket,user_id)
		) {$charset_collate};";

		$jobs          = self::table( TABLE_JOBS );
		$definitions[] = "CREATE TABLE {$jobs} (
			job_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			action varchar(64) NOT NULL,
			action_args longtext DEFAULT NULL,
			status varchar(20) NOT NULL,
			cursor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			processed bigint(20) unsigned NOT NULL DEFAULT 0,
			succeeded bigint(20) unsigned NOT NULL DEFAULT 0,
			failed bigint(20) unsigned NOT NULL DEFAULT 0,
			skipped bigint(20) unsigned NOT NULL DEFAULT 0,
			export_name varchar(191) DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			completed_at datetime DEFAULT NULL,
			PRIMARY KEY  (job_id),
			KEY run_status (run_id,status),
			KEY created_at (created_at)
		) {$charset_collate};";

		$job_items     = self::table( TABLE_JOB_ITEMS );
		$definitions[] = "CREATE TABLE {$job_items} (
			job_item_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			result varchar(20) NOT NULL,
			message text DEFAULT NULL,
			PRIMARY KEY  (job_item_id),
			KEY job_result (job_id,result)
		) {$charset_collate};";

		return $definitions;
	}
}
