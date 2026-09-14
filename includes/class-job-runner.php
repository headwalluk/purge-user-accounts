<?php
/**
 * Chunked, resumable execution of a job.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Advances a job by one bounded chunk.
 *
 * Mirrors the run Stepper: the cursor is on the row, the browser drives it over
 * AJAX, WP-CLI drives it with a plain loop.
 *
 * The important difference is that guards are re-applied to every chunk as it
 * is loaded, never trusted from selection time.
 */
class Job_Runner {

	/**
	 * Advance a job by one step.
	 *
	 * @param Job $job Job to advance.
	 * @return array<string,mixed> Progress payload.
	 */
	public static function step( Job $job ): array {
		$progress = array();

		try {
			$progress = self::execute_step( $job );
		} catch ( Run_Exception $caught_error ) {
			$job->mark_failed( $caught_error->getMessage() );

			$progress = array(
				'done'    => true,
				'aborted' => true,
				'message' => $caught_error->getMessage(),
			);
		}

		return $progress;
	}

	/**
	 * Process one chunk.
	 *
	 * @param Job $job Job to advance.
	 * @return array<string,mixed>
	 * @throws Run_Exception When the chunk cannot be processed honestly.
	 */
	protected static function execute_step( Job $job ): array {
		global $wpdb;

		$action = Action_Registry::get_action( $job->get_action_id() );

		if ( null === $action ) {
			throw new Run_Exception(
				esc_html__( 'That action is no longer available on this site, so the job was stopped.', 'purge-user-accounts' )
			);
		}

		$run = Run::load( $job->get_run_id() );

		if ( null === $run ) {
			throw new Run_Exception(
				esc_html__( 'The query this job was built from has gone, so the job was stopped.', 'purge-user-accounts' )
			);
		}

		$chunk_size  = max( 10, $action->get_chunk_size() );
		$items_table = Schema::table( TABLE_RUN_ITEMS );

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$items_table} WHERE run_id = %d AND user_id > %d ORDER BY user_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
				$run->get_id(),
				$job->get_cursor(),
				$chunk_size
			)
		);

		if ( ! is_array( $user_ids ) ) {
			throw new Run_Exception(
				esc_html__( 'The list of users could not be read, so the job was stopped rather than acting on a partial list.', 'purge-user-accounts' )
			);
		}

		if ( empty( $user_ids ) ) {
			$job->mark_complete();

			return self::build_progress( $job, $run, true );
		}

		$user_ids = array_map( 'absint', $user_ids );
		$args     = $job->get_args();

		// Re-applied here, on the chunk as loaded. A run built on Tuesday and
		// executed on Thursday may contain someone promoted since.
		$allow_privileged = ! empty( $args['allow_privileged'] ) && $action->allows_privileged_opt_in();
		$protected        = Guards::find_protected( $user_ids, $allow_privileged );

		$actionable = array();
		$result     = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			if ( isset( $protected[ $user_id ] ) ) {
				$result->skip( $user_id, Guards::describe_reason( $protected[ $user_id ] ) );
				continue;
			}

			$actionable[] = $user_id;
		}

		$is_dry_run = ! empty( $args['dry_run'] );

		if ( ! empty( $actionable ) ) {
			if ( $is_dry_run ) {
				// Every guard has run and every skip is recorded; only the
				// change itself is withheld. The last safety net before an
				// irreversible operation.
				foreach ( $actionable as $actionable_id ) {
					unset( $actionable_id );
					$result->succeed();
				}
			} else {
				$result->absorb( $action->apply( $actionable, $args ) );
			}
		}

		$job->record_chunk( $result, (int) max( $user_ids ) );

		return self::build_progress( $job, $run, false );
	}

	/**
	 * Shape the progress payload.
	 *
	 * @param Job  $job     Job being advanced.
	 * @param Run  $run     Run being acted upon.
	 * @param bool $is_done Whether the job has finished.
	 * @return array<string,mixed>
	 */
	protected static function build_progress( Job $job, Run $run, bool $is_done ): array {
		$counts  = $job->get_counts();
		$total   = max( 1, $run->get_matched_count() );
		$percent = (int) floor( min( 1.0, $counts['processed'] / $total ) * 100 );

		return array_merge(
			$counts,
			array(
				'dry_run'           => ! empty( $job->get_args()['dry_run'] ),
				'job_id'            => $job->get_id(),
				'total'             => $run->get_matched_count(),
				'progress_pct'      => $is_done ? 100 : $percent,
				'remaining_seconds' => $is_done ? 0 : self::estimate_remaining( $job, $run ),
				'done'              => $is_done,
				'aborted'           => false,
			)
		);
	}

	/**
	 * Seconds remaining, from what this job is actually achieving.
	 *
	 * A fixed rate per action cannot work: deleting accounts with no content on
	 * a bare site was measured at 485/second, while the same action on a
	 * WooCommerce site with order history is an order of magnitude slower. The
	 * declared rate is only a starting guess, used until the job has done enough
	 * to speak for itself.
	 *
	 * Elapsed time includes any pause, which makes a resumed job's estimate
	 * pessimistic rather than optimistic. That is the right direction to be
	 * wrong in.
	 *
	 * @param Job $job Job being advanced.
	 * @param Run $run Run being acted upon.
	 * @return int
	 */
	protected static function estimate_remaining( Job $job, Run $run ): int {
		$counts    = $job->get_counts();
		$remaining = max( 0, $run->get_matched_count() - $counts['processed'] );

		if ( 0 === $remaining ) {
			return 0;
		}

		$action       = Action_Registry::get_action( $job->get_action_id() );
		$assumed_rate = null === $action ? 100.0 : max( 0.1, $action->get_rate_per_second() );
		$elapsed      = max( 1, time() - $job->get_created_timestamp() );

		// Trust observed throughput only once there is enough of it to mean
		// something; a handful of rows in the first second says nothing.
		$observed_rate = $counts['processed'] >= 100 && $elapsed >= 2
			? $counts['processed'] / $elapsed
			: $assumed_rate;

		return (int) ceil( $remaining / max( 0.1, $observed_rate ) );
	}

	/**
	 * Seconds a job is expected to take, from the action's measured rate.
	 *
	 * @param Action $action Action to estimate.
	 * @param int    $total  Users to process.
	 * @return int
	 */
	public static function estimate_seconds( Action $action, int $total ): int {
		$rate = max( 0.1, $action->get_rate_per_second() );

		return (int) ceil( $total / $rate );
	}
}
