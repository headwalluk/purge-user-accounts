<?php
/**
 * One action applied to one run.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * A persisted, resumable application of an action.
 *
 * The cursor lives on the row, not in the session, so a job begun in the
 * browser can be finished from WP-CLI - which matters when a 40,000-user
 * deletion runs for the better part of an hour.
 */
class Job {

	/**
	 * Row from the jobs table.
	 *
	 * @var array<string,mixed>
	 */
	protected array $row;

	/**
	 * Wrap a jobs-table row.
	 *
	 * @param array<string,mixed> $row Database row.
	 */
	public function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * Create a job against a completed run.
	 *
	 * @param Run                 $run         Run to act on.
	 * @param Action              $action      Action to apply.
	 * @param array<string,mixed> $action_args Operator-supplied arguments.
	 * @param string              $export_name Basename of the pre-flight export.
	 * @return Job
	 * @throws Run_Exception When the job cannot be created.
	 */
	public static function create( Run $run, Action $action, array $action_args, string $export_name ): Job {
		global $wpdb;

		if ( ! $run->is_complete() ) {
			throw new Run_Exception(
				esc_html__( 'That query did not finish, so it cannot be acted upon. An incomplete result is not a result.', 'purge-user-accounts' )
			);
		}

		if ( $run->is_stale() ) {
			throw new Run_Exception(
				esc_html__( 'That result is more than a day old. People register, log in and buy things in the meantime — re-run the query before acting on it.', 'purge-user-accounts' )
			);
		}

		if ( $action->requires_export() && '' === $export_name ) {
			throw new Run_Exception(
				esc_html__( 'Export the result before acting on it. Once accounts are changed, that file is the only record of who was affected.', 'purge-user-accounts' )
			);
		}

		$encoded_args = wp_json_encode( $action_args );

		$inserted = $wpdb->insert(
			Schema::table( TABLE_JOBS ),
			array(
				'run_id'      => $run->get_id(),
				'action'      => $action->get_id(),
				'action_args' => is_string( $encoded_args ) ? $encoded_args : '{}',
				'status'      => JOB_STATUS_PENDING,
				'export_name' => '' === $export_name ? null : $export_name,
				'created_by'  => get_current_user_id(),
				'created_at'  => hwpua_now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			throw new Run_Exception(
				esc_html__( 'The job could not be created, so nothing was changed.', 'purge-user-accounts' )
			);
		}

		$job = self::load( (int) $wpdb->insert_id );

		if ( null === $job ) {
			throw new Run_Exception(
				esc_html__( 'The job was created but could not be read back, so it was not started.', 'purge-user-accounts' )
			);
		}

		return $job;
	}

	/**
	 * Load a job by id.
	 *
	 * @param int $job_id Job identifier.
	 * @return Job|null
	 */
	public static function load( int $job_id ): ?Job {
		global $wpdb;

		$table = Schema::table( TABLE_JOBS );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d", $job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
			ARRAY_A
		);

		return is_array( $row ) ? new self( $row ) : null;
	}

	/**
	 * Job identifier.
	 */
	public function get_id(): int {
		return (int) $this->row['job_id'];
	}

	/**
	 * Run this job acts on.
	 */
	public function get_run_id(): int {
		return (int) $this->row['run_id'];
	}

	/**
	 * Action identifier.
	 */
	public function get_action_id(): string {
		return (string) $this->row['action'];
	}

	/**
	 * Current lifecycle status.
	 */
	public function get_status(): string {
		return (string) $this->row['status'];
	}

	/**
	 * Highest user ID processed so far.
	 */
	public function get_cursor(): int {
		return (int) $this->row['cursor_user_id'];
	}

	/**
	 * Operator-supplied arguments.
	 *
	 * @return array<string,mixed>
	 */
	public function get_args(): array {
		$decoded = json_decode( (string) $this->row['action_args'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Counts so far.
	 *
	 * @return array<string,int>
	 */
	public function get_counts(): array {
		return array(
			'processed' => (int) $this->row['processed'],
			'succeeded' => (int) $this->row['succeeded'],
			'failed'    => (int) $this->row['failed'],
			'skipped'   => (int) $this->row['skipped'],
		);
	}

	/**
	 * Whether the job has finished.
	 */
	public function is_finished(): bool {
		return in_array( $this->get_status(), array( JOB_STATUS_COMPLETE, JOB_STATUS_FAILED ), true );
	}

	/**
	 * When the job was created, as a Unix timestamp.
	 */
	public function get_created_timestamp(): int {
		$parsed = strtotime( (string) $this->row['created_at'] . ' UTC' );

		return false === $parsed ? 0 : $parsed;
	}

	/**
	 * Persist column changes.
	 *
	 * @param array<string,mixed> $columns Column/value pairs.
	 * @return void
	 * @throws Run_Exception When the update cannot be written.
	 */
	public function update( array $columns ): void {
		global $wpdb;

		$updated = $wpdb->update( Schema::table( TABLE_JOBS ), $columns, array( 'job_id' => $this->get_id() ) );

		if ( false === $updated ) {
			throw new Run_Exception(
				esc_html__( 'The job state could not be saved, so it was stopped rather than left inconsistent.', 'purge-user-accounts' )
			);
		}

		$this->row = array_merge( $this->row, $columns );
	}

	/**
	 * Record what happened to a chunk.
	 *
	 * @param Action_Result $result     Chunk outcome.
	 * @param int           $new_cursor Highest user ID handled.
	 * @return void
	 */
	public function record_chunk( Action_Result $result, int $new_cursor ): void {
		$counts = $this->get_counts();

		$this->write_items( $result->items );

		$this->update(
			array(
				'status'         => JOB_STATUS_RUNNING,
				'cursor_user_id' => $new_cursor,
				'processed'      => $counts['processed'] + $result->succeeded + count( $result->items ),
				'succeeded'      => $counts['succeeded'] + $result->succeeded,
				'failed'         => $counts['failed'] + $result->count_failed(),
				'skipped'        => $counts['skipped'] + $result->count_skipped(),
			)
		);
	}

	/**
	 * Write per-user failures and skips.
	 *
	 * Successes are not written: the operator's export already records who was
	 * targeted, and 40,000 "this worked" rows would be storage for nothing. Every
	 * failure gets a row with a reason, which is the durable trace that stops a
	 * caught error from leaving no evidence.
	 *
	 * @param array<int,array{user_id:int,result:string,message:string}> $items Outcomes.
	 * @return void
	 */
	protected function write_items( array $items ): void {
		global $wpdb;

		$items_table = Schema::table( TABLE_JOB_ITEMS );

		foreach ( $items as $item ) {
			$written = $wpdb->insert(
				$items_table,
				array(
					'job_id'  => $this->get_id(),
					'user_id' => (int) $item['user_id'],
					'result'  => (string) $item['result'],
					'message' => mb_substr( (string) $item['message'], 0, 2000 ),
				),
				array( '%d', '%d', '%s', '%s' )
			);

			if ( false === $written ) {
				hwpua_log_error(
					sprintf( 'Job %d could not record the outcome for user %d: %s', $this->get_id(), (int) $item['user_id'], (string) $item['message'] )
				);
			}
		}
	}

	/**
	 * Mark the job complete.
	 *
	 * @return void
	 */
	public function mark_complete(): void {
		$this->update(
			array(
				'status'       => JOB_STATUS_COMPLETE,
				'completed_at' => hwpua_now(),
			)
		);
	}

	/**
	 * Mark the job failed, recording why.
	 *
	 * @param string $reason Operator-safe explanation.
	 * @return void
	 */
	public function mark_failed( string $reason ): void {
		$this->update(
			array(
				'status'       => JOB_STATUS_FAILED,
				'completed_at' => hwpua_now(),
			)
		);

		hwpua_log_error( sprintf( 'Job %d failed: %s', $this->get_id(), $reason ) );
	}

	/**
	 * Halt the job, leaving the cursor so it can be resumed.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$this->update( array( 'status' => JOB_STATUS_CANCELLED ) );
	}
}
