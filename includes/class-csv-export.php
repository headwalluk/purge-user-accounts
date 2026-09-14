<?php
/**
 * Streamed CSV export of a run.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Writes a run's matches to a CSV file, then serves it through PHP.
 *
 * Two things about this file are load-bearing rather than cosmetic:
 *
 * 1. Cells are neutralised against spreadsheet formula injection. Display
 *    names, logins and email addresses are attacker-controlled, and the export
 *    exists precisely BECAUSE the operator is looking at hostile accounts.
 *
 * 2. "Unknown" is written literally. An empty cell in a spreadsheet reads as
 *    "never", which is the exact misreading the whole last-login design exists
 *    to prevent.
 */
class Csv_Export {

	/**
	 * Rows read per batch. Bounds memory; nothing accumulates across batches.
	 *
	 * @var int
	 */
	const CHUNK_SIZE = DEF_EXPORT_CHUNK;

	/**
	 * Write a completed run to a file and return its path.
	 *
	 * @param Run $run Completed run.
	 * @return string Absolute path to the written file.
	 * @throws Run_Exception When the run is unusable or the file cannot be written.
	 */
	public static function write( Run $run ): string {
		global $wpdb;

		if ( ! $run->is_complete() ) {
			throw new Run_Exception(
				esc_html__( 'That query did not finish, so it cannot be exported. An incomplete result is not a result.', 'purge-user-accounts' )
			);
		}

		Export_Directory::ensure_ready();

		$file_path = Export_Directory::build_file_path( $run->get_id() );
		$handle    = @fopen( $file_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Streaming a large file; WP_Filesystem loads it all into memory. Failure is handled below.

		if ( false === $handle ) {
			throw new Run_Exception(
				esc_html__( 'The export file could not be created. Check the export directory is writable — the Settings tab shows where it is.', 'purge-user-accounts' )
			);
		}

		$meta_keys = self::get_meta_columns();

		self::write_row( $handle, self::get_headings( $meta_keys ), $file_path );

		$items_table = Schema::table( TABLE_RUN_ITEMS );
		$cursor      = 0;

		$capabilities_key = Environment::get_capabilities_meta_key();

		// Everything the standard columns need comes from this one query.
		// Calling get_userdata() per row instead hydrates a WP_User for every
		// match and leaves it in the object cache - measured at 324 MB across
		// 38,801 rows, five times the whole plugin's memory budget.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants; every value is bound.
		$chunk_sql = "SELECT ri.user_id, ri.matched_rule,
				u.user_login, u.user_email, u.display_name, u.user_registered,
				caps.meta_value AS capabilities,
				firstname.meta_value AS first_name,
				lastname.meta_value AS last_name,
				lastseen.meta_value AS last_seen_raw
			FROM {$items_table} ri
			JOIN {$wpdb->users} u ON u.ID = ri.user_id
			LEFT JOIN {$wpdb->usermeta} caps ON caps.user_id = u.ID AND caps.meta_key = %s
			LEFT JOIN {$wpdb->usermeta} firstname ON firstname.user_id = u.ID AND firstname.meta_key = 'first_name'
			LEFT JOIN {$wpdb->usermeta} lastname ON lastname.user_id = u.ID AND lastname.meta_key = 'last_name'
			LEFT JOIN {$wpdb->usermeta} lastseen ON lastseen.user_id = u.ID AND lastseen.meta_key = %s
			WHERE ri.run_id = %d AND ri.user_id > %d
			ORDER BY ri.user_id LIMIT %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$source          = Last_Login::get_active_source();
		$last_seen_key   = null === $source ? '' : $source->meta_key;
		$coverage        = Last_Login::get_coverage();
		$earliest_record = null === $coverage ? '' : $coverage->earliest_record;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( $chunk_sql, $capabilities_key, $last_seen_key, $run->get_id(), $cursor, self::CHUNK_SIZE ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here with bound values.
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			$user_ids = array();
			foreach ( $rows as $row ) {
				$user_ids[] = (int) $row->user_id;
			}

			// Extra meta columns still need a lookup, but one for the batch.
			if ( ! empty( $meta_keys ) ) {
				update_meta_cache( 'user', $user_ids );
			}

			foreach ( $rows as $row ) {
				self::write_row( $handle, self::build_row( $row, $meta_keys, $source, $earliest_record ), $file_path );
				$cursor = (int) $row->user_id;
			}

			// Nothing may accumulate across batches. Without this the object
			// cache grows until it holds every matched user.
			foreach ( $user_ids as $cached_user_id ) {
				wp_cache_delete( $cached_user_id, 'user_meta' );
			}

			if ( count( $rows ) < self::CHUNK_SIZE ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fopen above.

		// Owner-only: where the web server and PHP run as different users this
		// makes the file unservable as a static asset on any server.
		Export_Directory::harden_file( $file_path );

		return $file_path;
	}

	/**
	 * Column headings.
	 *
	 * @param string[] $meta_keys Extra user meta columns.
	 * @return string[]
	 */
	protected static function get_headings( array $meta_keys ): array {
		$headings = array(
			'user_id',
			'user_login',
			'first_name',
			'last_name',
			'display_name',
			'user_email',
			'roles',
			'registered',
			'last_seen',
			'last_seen_state',
			'why_matched',
		);

		return array_merge( $headings, $meta_keys );
	}

	/**
	 * Build one output row.
	 *
	 * @param object                 $row             Joined run item.
	 * @param string[]               $meta_keys       Extra user meta columns.
	 * @param Last_Login_Source|null $source          Active last-login source.
	 * @param string                 $earliest_record Earliest record the source holds.
	 * @return array<int,string>
	 */
	protected static function build_row( object $row, array $meta_keys, ?Last_Login_Source $source, string $earliest_record ): array {
		$user_id = (int) $row->user_id;

		$values = array(
			(string) $user_id,
			(string) $row->user_login,
			(string) ( $row->first_name ?? '' ),
			(string) ( $row->last_name ?? '' ),
			(string) $row->display_name,
			(string) $row->user_email,
			implode( '|', self::read_roles( (string) ( $row->capabilities ?? '' ) ) ),
			(string) $row->user_registered,
			// Never an empty cell: a blank here reads as "never" in a spreadsheet.
			self::describe_last_seen( $row, $source, $earliest_record )['label'],
			self::describe_last_seen( $row, $source, $earliest_record )['state'],
			(string) ( $row->matched_rule ?? '' ),
		);

		foreach ( $meta_keys as $meta_key ) {
			$meta_value = get_user_meta( $user_id, $meta_key, true );
			$values[]   = is_scalar( $meta_value ) ? (string) $meta_value : (string) wp_json_encode( $meta_value );
		}

		return $values;
	}

	/**
	 * Role slugs from a serialised capabilities value.
	 *
	 * @param string $capabilities Serialised capabilities meta.
	 * @return string[]
	 */
	protected static function read_roles( string $capabilities ): array {
		$roles = array();

		if ( '' !== $capabilities ) {
			$decoded = maybe_unserialize( $capabilities );

			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $role_slug => $is_granted ) {
					if ( $is_granted ) {
						$roles[] = (string) $role_slug;
					}
				}
			}
		}

		return $roles;
	}

	/**
	 * Apply the Unknown/Never judgement to an already-fetched row.
	 *
	 * Mirrors Last_Login::describe_for_user(), but reads the value joined into
	 * the chunk query rather than issuing a meta lookup per row.
	 *
	 * @param object                 $row             Joined run item.
	 * @param Last_Login_Source|null $source          Active source, if any.
	 * @param string                 $earliest_record Earliest record the source holds.
	 * @return array{state:string,label:string}
	 */
	protected static function describe_last_seen( object $row, ?Last_Login_Source $source, string $earliest_record ): array {
		if ( null === $source ) {
			return array(
				'state' => 'untracked',
				'label' => __( 'not tracked', 'purge-user-accounts' ),
			);
		}

		$timestamp = $source->to_timestamp( $row->last_seen_raw ?? '' );

		if ( $timestamp > 0 ) {
			return array(
				'state' => 'seen',
				'label' => gmdate( 'Y-m-d', $timestamp ),
			);
		}

		if ( '' !== $earliest_record && (string) $row->user_registered < $earliest_record ) {
			return array(
				'state' => 'unknown',
				'label' => __( 'Unknown', 'purge-user-accounts' ),
			);
		}

		return array(
			'state' => 'never',
			'label' => __( 'Never', 'purge-user-accounts' ),
		);
	}

	/**
	 * Extra user meta columns an integration or site wants included.
	 *
	 * @return string[]
	 */
	protected static function get_meta_columns(): array {
		/**
		 * Filters the extra user meta keys included in an export.
		 *
		 * @param string[] $meta_keys Meta keys.
		 */
		$meta_keys = apply_filters( 'hwpua_csv_columns', array() );

		return is_array( $meta_keys ) ? array_values( array_filter( array_map( 'strval', $meta_keys ) ) ) : array();
	}

	/**
	 * Write one formula-safe row.
	 *
	 * @param resource          $handle    Open file handle.
	 * @param array<int,string> $cells     Row values.
	 * @param string            $file_path Path, for the failure message.
	 * @return void
	 * @throws Run_Exception When the row cannot be written.
	 */
	protected static function write_row( $handle, array $cells, string $file_path ): void {
		$written = fputcsv( $handle, array_map( array( __CLASS__, 'protect_cell' ), $cells ), ',', '"', '\\' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Streaming; WP_Filesystem has no row-wise equivalent.

		if ( false === $written ) {
			throw new Run_Exception(
				sprintf(
					/* translators: %s: file path. */
					esc_html__( 'The export could not be written to %s. It may be incomplete — delete it and try again.', 'purge-user-accounts' ),
					esc_html( $file_path )
				)
			);
		}
	}

	/**
	 * Neutralise spreadsheet formulas while preserving real values.
	 *
	 * A bot registering with the display name `=cmd|'/c calc'!A1` produces a
	 * spreadsheet that attacks whoever opens the export. Excel, LibreOffice and
	 * Sheets all treat a leading =, +, - or @ as a formula.
	 *
	 * Technique adapted from the Resus Room User Audit plugin
	 * (GPL-2.0-or-later); see dev-notes/reference/prior-art-resus-room-user-audit.md §2.3.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function protect_cell( string $value ): string {
		$cleaned = str_replace( "\0", '', $value );

		// Leading whitespace and control characters count: a tab before an =
		// still opens a formula in Excel.
		if ( 1 === preg_match( '/^(?:[\t\r\n]|\s*[=+\-@])/', $cleaned ) ) {
			$cleaned = "'" . $cleaned;
		}

		return $cleaned;
	}
}
