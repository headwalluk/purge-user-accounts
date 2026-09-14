<?php
/**
 * Per-stage timing and memory measurement.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Times a run stage by stage and reports peak memory.
 *
 * The point is regression visibility. The design rests on two measured claims -
 * that the seed never materialises IDs in PHP, and that peak memory is flat
 * regardless of user count - and both need to stay true as filters are added.
 */
class Benchmark {

	/**
	 * Execute a run to completion, measuring each step.
	 *
	 * @param array<int,array<string,mixed>> $raw_spec Criteria to benchmark.
	 * @return array<string,mixed> Measurements.
	 * @throws Run_Exception When the specification is invalid.
	 */
	public static function measure_run( array $raw_spec ): array {
		global $wpdb;

		$spec = Run_Builder::validate( $raw_spec );

		$baseline_memory = memory_get_usage( true );
		$user_total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		$run        = Run::create( $spec );
		$plan       = Run_Builder::plan( $spec );
		$stage_time = array();
		$step_count = 0;
		$progress   = array( 'done' => false );
		$run_start  = microtime( true );

		while ( empty( $progress['done'] ) && $step_count < 100000 ) {
			$stage_index = $run->get_stage_index();
			$stage_kind  = isset( $plan[ $stage_index ] ) ? $plan[ $stage_index ]->kind : 'unknown';

			$step_start = microtime( true );
			$progress   = Stepper::step( $run );
			$step_ms    = ( microtime( true ) - $step_start ) * 1000;

			if ( ! isset( $stage_time[ $stage_kind ] ) ) {
				$stage_time[ $stage_kind ] = array(
					'steps' => 0,
					'ms'    => 0.0,
				);
			}

			++$stage_time[ $stage_kind ]['steps'];
			$stage_time[ $stage_kind ]['ms'] += $step_ms;
			++$step_count;
		}

		$elapsed_ms  = ( microtime( true ) - $run_start ) * 1000;
		$peak_memory = memory_get_peak_usage( true ) - $baseline_memory;

		return array(
			'users_total'    => $user_total,
			'matched'        => $run->count_items(),
			'status'         => $run->get_status(),
			'failure'        => $run->get_failure_reason(),
			'steps'          => $step_count,
			'elapsed_ms'     => $elapsed_ms,
			'per_stage'      => $stage_time,
			'peak_memory_mb' => $peak_memory / 1048576,
			'run_id'         => $run->get_id(),
		);
	}

	/**
	 * Compare ways of expressing "has no comments".
	 *
	 * Answers the open question of whether the explicit scratch table earns its
	 * place, or whether the optimiser materialises a subquery just as well.
	 * `wp_comments` has no index on `user_id`, which is what makes a correlated
	 * NOT EXISTS potentially catastrophic - so all three are measured rather
	 * than argued about.
	 *
	 * @return array<int,array<string,mixed>> One row per strategy.
	 */
	public static function compare_comment_strategies(): array {
		global $wpdb;

		$scratch_table = Schema::table( TABLE_RUN_SCRATCH );
		$probe_run_id  = 999999999;

		// Strategy A: pre-materialise into our scratch table, then join.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$scratch_table} WHERE run_id = %d", $probe_run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.

		$prepare_start = microtime( true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$scratch_table} (run_id, bucket, user_id)
				 SELECT %d, 'commenter', c.user_id FROM {$wpdb->comments} c WHERE c.user_id > 0
				 GROUP BY c.user_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants.
				$probe_run_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prepare_ms = ( microtime( true ) - $prepare_start ) * 1000;

		// These are SQL templates prepared later by the loop below, so the
		// placeholders are deliberately not bound here.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$strategies = array(
			'scratch table'         => array(
				'sql'    => "SELECT COUNT(*) FROM {$wpdb->users} u WHERE NOT EXISTS (
					SELECT 1 FROM {$scratch_table} s WHERE s.run_id = %d AND s.bucket = 'commenter' AND s.user_id = u.ID )",
				'params' => array( $probe_run_id ),
				'setup'  => $prepare_ms,
			),
			'NOT IN subquery'       => array(
				'sql'    => "SELECT COUNT(*) FROM {$wpdb->users} u WHERE u.ID NOT IN (
					SELECT c.user_id FROM {$wpdb->comments} c WHERE c.user_id > 0 )",
				'params' => array(),
				'setup'  => 0.0,
			),
			'correlated NOT EXISTS' => array(
				'sql'    => "SELECT COUNT(*) FROM {$wpdb->users} u WHERE NOT EXISTS (
					SELECT 1 FROM {$wpdb->comments} c WHERE c.user_id = u.ID )",
				'params' => array(),
				'setup'  => 0.0,
			),
		);

		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$results = array();

		foreach ( $strategies as $strategy_name => $strategy ) {
			$query_start = microtime( true );

			$matched = empty( $strategy['params'] )
				? $wpdb->get_var( $strategy['sql'] ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static SQL built from our own table names.
				: $wpdb->get_var( $wpdb->prepare( $strategy['sql'], $strategy['params'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Prepared with bound values.

			$query_ms = ( microtime( true ) - $query_start ) * 1000;

			$results[] = array(
				'strategy' => $strategy_name,
				'setup_ms' => sprintf( '%.1f', $strategy['setup'] ),
				'query_ms' => sprintf( '%.1f', $query_ms ),
				'total_ms' => sprintf( '%.1f', $strategy['setup'] + $query_ms ),
				'matched'  => (int) $matched,
			);
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$scratch_table} WHERE run_id = %d", $probe_run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.

		return $results;
	}

	/**
	 * Ask MySQL how it intends to execute the seed statement.
	 *
	 * The design assumes the optimiser uses `type_status_author` on posts and
	 * the primary key on users. Assumed is not verified, so this prints the
	 * plan rather than trusting it.
	 *
	 * @param array<int,array<string,mixed>> $raw_spec Criteria to explain.
	 * @return array<int,array<string,mixed>> EXPLAIN rows.
	 * @throws Run_Exception When the specification is invalid.
	 */
	public static function explain_seed( array $raw_spec ): array {
		global $wpdb;

		$spec = Run_Builder::validate( $raw_spec );
		$run  = Run::create( $spec );

		$where_parts  = array( 'u.ID > %d', 'u.ID <= %d' );
		$where_params = array( 0, $run->get_max_user_id() );

		foreach ( Run_Builder::get_sql_criteria( $spec ) as $criterion ) {
			$filter = Integration_Registry::get_filter( (string) $criterion['id'] );

			if ( null === $filter ) {
				continue;
			}

			list( $predicate_sql, $predicate_params ) = $filter->get_predicate( (array) $criterion['args'], $run->get_id() );

			$where_parts[] = SENSE_HAS === $criterion['sense']
				? "( {$predicate_sql} )"
				: "NOT ( {$predicate_sql} )";

			$where_params = array_merge( $where_params, $predicate_params );
		}

		$where_sql   = implode( ' AND ', $where_parts );
		$explain_sql = "EXPLAIN SELECT u.ID FROM {$wpdb->users} u WHERE {$where_sql}";

		$rows = $wpdb->get_results( $wpdb->prepare( $explain_sql, $where_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders built above; every value is bound.

		// The run was created only to obtain a ceiling and an id for scratch.
		$run->drop_scratch();

		return is_array( $rows ) ? $rows : array();
	}
}
