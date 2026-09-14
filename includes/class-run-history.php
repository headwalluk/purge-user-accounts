<?php
/**
 * Querying and describing past runs.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Read-side helpers for the Results and History screens.
 *
 * History is not a nicety. When a site owner asks what happened to a particular
 * customer, this is the answer.
 */
class Run_History {

	/**
	 * The most recent completed run, or null.
	 *
	 * @return Run|null
	 */
	public static function get_latest_complete(): ?Run {
		global $wpdb;

		$runs_table = Schema::table( TABLE_RUNS );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$runs_table} WHERE status = %s ORDER BY run_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
				RUN_STATUS_COMPLETE
			),
			ARRAY_A
		);

		return is_array( $row ) ? new Run( $row ) : null;
	}

	/**
	 * Recent runs, newest first.
	 *
	 * Abandoned runs are excluded: an estimate is throwaway and should not
	 * clutter the audit trail.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent( int $limit = 50 ): array {
		global $wpdb;

		$runs_table = Schema::table( TABLE_RUNS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$runs_table} WHERE status != %s ORDER BY run_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
				RUN_STATUS_ABANDONED,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete runs and jobs that are past their retention period.
	 *
	 * Runs go after DEF_RUN_RETENTION_DAYS and abandoned estimates after
	 * DEF_ABANDONED_RUN_RETENTION_SECONDS, except a run whose job is still
	 * pending, running or stopped, which would otherwise become unresumable.
	 * Jobs and their items go after DEF_JOB_RETENTION_DAYS.
	 *
	 * @return void
	 */
	public static function prune_expired(): void {
		global $wpdb;

		$runs_table      = Schema::table( TABLE_RUNS );
		$run_items_table = Schema::table( TABLE_RUN_ITEMS );
		$scratch_table   = Schema::table( TABLE_RUN_SCRATCH );
		$jobs_table      = Schema::table( TABLE_JOBS );
		$job_items_table = Schema::table( TABLE_JOB_ITEMS );

		$run_cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( DEF_RUN_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$abandoned_cutoff = gmdate( 'Y-m-d H:i:s', time() - DEF_ABANDONED_RUN_RETENTION_SECONDS );
		$job_cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( DEF_JOB_RETENTION_DAYS * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants.
		$expired_run_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT r.run_id FROM {$runs_table} r
				WHERE ( r.created_at < %s OR ( r.status = %s AND r.created_at < %s ) )
				  AND NOT EXISTS (
					SELECT 1 FROM {$jobs_table} j
					WHERE j.run_id = r.run_id AND j.status IN ( %s, %s, %s )
				  )",
				$run_cutoff,
				RUN_STATUS_ABANDONED,
				$abandoned_cutoff,
				JOB_STATUS_PENDING,
				JOB_STATUS_RUNNING,
				JOB_STATUS_CANCELLED
			)
		);

		if ( '' !== $wpdb->last_error ) {
			hwpua_log_error( sprintf( 'Expired runs could not be listed for pruning: %s', $wpdb->last_error ) );
		}

		$expired_job_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT job_id FROM {$jobs_table} WHERE created_at < %s", $job_cutoff )
		);

		if ( '' !== $wpdb->last_error ) {
			hwpua_log_error( sprintf( 'Expired jobs could not be listed for pruning: %s', $wpdb->last_error ) );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Items before their parent row, so a failure part-way leaves no orphans.
		$deletions = array(
			'run_id' => array(
				'ids'    => array_map( 'absint', (array) $expired_run_ids ),
				'tables' => array( $run_items_table, $scratch_table, $runs_table ),
			),
			'job_id' => array(
				'ids'    => array_map( 'absint', (array) $expired_job_ids ),
				'tables' => array( $job_items_table, $jobs_table ),
			),
		);

		foreach ( $deletions as $id_column => $deletion ) {
			foreach ( $deletion['ids'] as $expired_id ) {
				foreach ( $deletion['tables'] as $table_name ) {
					$deleted = $wpdb->delete( $table_name, array( $id_column => $expired_id ), array( '%d' ) );

					if ( false === $deleted ) {
						hwpua_log_error( sprintf( 'Could not prune %s %d from %s: %s', $id_column, $expired_id, $table_name, $wpdb->last_error ) );
					}
				}
			}
		}
	}

	/**
	 * Match reasons for a run, largest group first.
	 *
	 * @param Run $run Run to summarise.
	 * @return array<int,array<string,mixed>>
	 */
	public static function summarise_reasons( Run $run ): array {
		global $wpdb;

		$items_table = Schema::table( TABLE_RUN_ITEMS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
		$reason_sql = "SELECT COALESCE(matched_rule, '') AS reason, COUNT(*) AS total
			FROM {$items_table} WHERE run_id = %d
			GROUP BY reason ORDER BY total DESC LIMIT 25";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results(
			$wpdb->prepare( $reason_sql, $run->get_id() ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here with a bound value.
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Users on the site.
	 *
	 * @return int
	 */
	public static function count_users(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	}

	/**
	 * Describe a run's criteria in the operator's language.
	 *
	 * The criteria list is shown beside every result, so a count is never
	 * detached from the question that produced it.
	 *
	 * @param Run $run Run to describe.
	 * @return string
	 */
	public static function describe_spec( Run $run ): string {
		$descriptions = array();

		foreach ( $run->get_filter_spec() as $criterion ) {
			if ( ! is_array( $criterion ) || ! isset( $criterion['id'] ) ) {
				continue;
			}

			$filter = Integration_Registry::get_filter( (string) $criterion['id'] );
			$label  = null === $filter ? (string) $criterion['id'] : $filter->get_label();
			$sense  = (string) ( $criterion['sense'] ?? SENSE_HAS_NOT );

			$descriptions[] = ( null !== $filter && $filter->supports_sense() && SENSE_HAS_NOT === $sense )
				? sprintf(
					/* translators: %s: criterion label. */
					__( 'NOT: %s', 'purge-user-accounts' ),
					$label
				)
				: $label;
		}

		return empty( $descriptions )
			? __( 'every user', 'purge-user-accounts' )
			: implode( ' · ', $descriptions );
	}

	/**
	 * Operator-facing status wording.
	 *
	 * @param Run $run Run to describe.
	 * @return string
	 */
	public static function describe_status( Run $run ): string {
		$wording = array(
			RUN_STATUS_BUILDING  => __( 'unfinished', 'purge-user-accounts' ),
			RUN_STATUS_COMPLETE  => __( 'complete', 'purge-user-accounts' ),
			RUN_STATUS_FAILED    => __( 'stopped', 'purge-user-accounts' ),
			RUN_STATUS_ABANDONED => __( 'abandoned', 'purge-user-accounts' ),
		);

		return $wording[ $run->get_status() ] ?? $run->get_status();
	}
}
