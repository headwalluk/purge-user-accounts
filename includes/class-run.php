<?php
/**
 * A materialised, persisted result set.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * One query execution and its stored matches.
 *
 * A run is not "execute a query and hold the answer in a variable". It is a row
 * in hwpua_runs plus N rows in hwpua_run_items, written once and addressed
 * repeatedly - which is what makes paging stable, export streamable, deletion
 * resumable and the whole thing auditable.
 *
 * See dev-notes/03-query-engine.md.
 */
class Run {

	/**
	 * Row from the runs table.
	 *
	 * @var array<string,mixed>
	 */
	protected array $row;

	/**
	 * Wrap a runs-table row.
	 *
	 * @param array<string,mixed> $row Database row.
	 */
	public function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * Create a run and snapshot the current maximum user ID.
	 *
	 * The ceiling matters: without it a long build silently changes scope as
	 * people register. Accounts created mid-run are picked up by the next run.
	 *
	 * @param array<string,mixed> $filter_spec Validated filter specification.
	 * @param string              $preset_name Optional preset this came from.
	 * @return Run
	 * @throws Run_Exception When the row cannot be written.
	 */
	public static function create( array $filter_spec, string $preset_name = '' ): Run {
		global $wpdb;

		$encoded_spec = wp_json_encode( $filter_spec );
		$encoded_spec = is_string( $encoded_spec ) ? $encoded_spec : '{}';
		$max_user_id  = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" );

		if ( '' !== $wpdb->last_error ) {
			throw new Run_Exception(
				esc_html__( 'The user table could not be read. No run was started.', 'purge-user-accounts' )
			);
		}

		$inserted = $wpdb->insert(
			Schema::table( TABLE_RUNS ),
			array(
				'filter_spec' => $encoded_spec,
				'spec_hash'   => hash( 'sha256', $encoded_spec ),
				'preset_name' => '' === $preset_name ? null : $preset_name,
				'status'      => RUN_STATUS_BUILDING,
				'max_user_id' => $max_user_id,
				'created_by'  => get_current_user_id(),
				'created_at'  => hwpua_now(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			throw new Run_Exception(
				esc_html__( 'The run could not be created. Check that the plugin tables exist.', 'purge-user-accounts' )
			);
		}

		$run = self::load( (int) $wpdb->insert_id );

		if ( null === $run ) {
			throw new Run_Exception(
				esc_html__( 'The run was created but could not be read back.', 'purge-user-accounts' )
			);
		}

		return $run;
	}

	/**
	 * Load a run by id.
	 *
	 * @param int $run_id Run identifier.
	 * @return Run|null
	 */
	public static function load( int $run_id ): ?Run {
		global $wpdb;

		$table = Schema::table( TABLE_RUNS );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d", $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
			ARRAY_A
		);

		return is_array( $row ) ? new self( $row ) : null;
	}

	/**
	 * Run identifier.
	 */
	public function get_id(): int {
		return (int) $this->row['run_id'];
	}

	/**
	 * Current lifecycle status.
	 */
	public function get_status(): string {
		return (string) $this->row['status'];
	}

	/**
	 * Index of the stage currently being executed.
	 */
	public function get_stage_index(): int {
		return (int) $this->row['stage_index'];
	}

	/**
	 * Cursor within the current stage, always a user ID.
	 */
	public function get_stage_cursor(): int {
		return (int) $this->row['stage_cursor'];
	}

	/**
	 * Highest user ID at the moment the run started.
	 */
	public function get_max_user_id(): int {
		return (int) $this->row['max_user_id'];
	}

	/**
	 * Final match count, meaningful only once complete.
	 */
	public function get_matched_count(): int {
		return (int) $this->row['matched_count'];
	}

	/**
	 * The stored filter specification.
	 *
	 * @return array<string,mixed>
	 */
	public function get_filter_spec(): array {
		$decoded = json_decode( (string) $this->row['filter_spec'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Why the run failed, or an empty string.
	 */
	public function get_failure_reason(): string {
		return (string) ( $this->row['failure_reason'] ?? '' );
	}

	/**
	 * Whether every stage has finished.
	 *
	 * An incomplete run must never be acted upon, and the UI must not offer it.
	 */
	public function is_complete(): bool {
		return RUN_STATUS_COMPLETE === $this->get_status();
	}

	/**
	 * Seconds since the run completed, or -1 when it has not.
	 */
	public function get_age_seconds(): int {
		$completed_at = (string) ( $this->row['completed_at'] ?? '' );
		$age_seconds  = -1;

		if ( '' !== $completed_at ) {
			$age_seconds = max( 0, time() - (int) strtotime( $completed_at . ' UTC' ) );
		}

		return $age_seconds;
	}

	/**
	 * Whether the run is too old to act on without rebuilding.
	 *
	 * Staleness is a question of relevance rather than safety - guards are
	 * re-applied at execution time regardless of age.
	 */
	public function is_stale(): bool {
		/**
		 * Filters how long a completed run stays actionable, in seconds.
		 *
		 * @param int $stale_seconds Default one day.
		 */
		$stale_seconds = (int) apply_filters( 'hwpua_run_stale_seconds', DEF_RUN_STALE_SECONDS );
		$age_seconds   = $this->get_age_seconds();

		return $age_seconds < 0 || $age_seconds > $stale_seconds;
	}

	/**
	 * Persist column changes and refresh the in-memory row.
	 *
	 * @param array<string,mixed> $columns Column/value pairs.
	 * @return void
	 * @throws Run_Exception When the update cannot be written.
	 */
	public function update( array $columns ): void {
		global $wpdb;

		$updated = $wpdb->update(
			Schema::table( TABLE_RUNS ),
			$columns,
			array( 'run_id' => $this->get_id() )
		);

		if ( false === $updated ) {
			throw new Run_Exception(
				esc_html__( 'The run state could not be saved, so the run was stopped rather than left inconsistent.', 'purge-user-accounts' )
			);
		}

		$this->row = array_merge( $this->row, $columns );
	}

	/**
	 * Advance to the next stage, resetting the cursor.
	 *
	 * @return void
	 */
	public function advance_stage(): void {
		$this->update(
			array(
				'stage_index'  => $this->get_stage_index() + 1,
				'stage_cursor' => 0,
			)
		);
	}

	/**
	 * Move the cursor within the current stage.
	 *
	 * @param int $cursor_user_id Highest user ID handled so far.
	 * @return void
	 */
	public function set_cursor( int $cursor_user_id ): void {
		$this->update( array( 'stage_cursor' => $cursor_user_id ) );
	}

	/**
	 * Count the rows currently matched.
	 */
	public function count_items(): int {
		global $wpdb;

		$table = Schema::table( TABLE_RUN_ITEMS );

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %d", $this->get_id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
		);
	}

	/**
	 * Mark the run complete and record the final count.
	 *
	 * @return void
	 */
	public function mark_complete(): void {
		$this->update(
			array(
				'status'        => RUN_STATUS_COMPLETE,
				'matched_count' => $this->count_items(),
				'completed_at'  => hwpua_now(),
			)
		);
	}

	/**
	 * Mark the run failed, recording why.
	 *
	 * @param string $reason Operator-safe explanation.
	 * @return void
	 */
	public function mark_failed( string $reason ): void {
		$this->update(
			array(
				'status'         => RUN_STATUS_FAILED,
				'failure_reason' => $reason,
			)
		);

		hwpua_log_error( sprintf( 'Run %d failed: %s', $this->get_id(), $reason ) );
	}

	/**
	 * Delete the scratch rows for this run.
	 *
	 * @return void
	 */
	public function drop_scratch(): void {
		global $wpdb;

		$wpdb->delete( Schema::table( TABLE_RUN_SCRATCH ), array( 'run_id' => $this->get_id() ), array( '%d' ) );
	}
}
