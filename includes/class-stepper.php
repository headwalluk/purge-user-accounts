<?php
/**
 * Chunked, resumable execution of a run's stage plan.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Advances a run by one bounded chunk of work.
 *
 * The browser drives this over AJAX with a progress bar; WP-CLI drives it with
 * a plain loop. Same code path, so the two cannot drift.
 *
 * Resume state is entirely `stage_index` plus `stage_cursor` on the run row, and
 * cursors are always user IDs rather than offsets - LIMIT/OFFSET degrades as the
 * offset grows and is wrong under concurrent modification.
 */
class Stepper {

	/**
	 * Smallest permitted chunk, guarding against a stalled cursor.
	 *
	 * @var int
	 */
	const MIN_CHUNK_SIZE = 10;

	/**
	 * Advance a run by one step.
	 *
	 * Failures mark the run failed and are reported, never swallowed: a data
	 * source that could not be consulted must abort rather than let every user
	 * look like they have no orders.
	 *
	 * @param Run $run Run to advance.
	 * @return array<string,mixed> Progress payload for the caller.
	 */
	public static function step( Run $run ): array {
		$progress = array();

		try {
			$progress = self::execute_step( $run );
		} catch ( Run_Exception $caught_error ) {
			$run->mark_failed( $caught_error->getMessage() );
			$run->drop_scratch();

			$progress = array(
				'done'    => true,
				'aborted' => true,
				'message' => $caught_error->getMessage(),
			);
		}

		return $progress;
	}

	/**
	 * Run one stage chunk and report where the run now stands.
	 *
	 * @param Run $run Run to advance.
	 * @return array<string,mixed>
	 * @throws Run_Exception When a stage cannot complete honestly.
	 */
	protected static function execute_step( Run $run ): array {
		$spec        = $run->get_filter_spec();
		$stage_plan  = Run_Builder::plan( $spec );
		$stage_index = $run->get_stage_index();
		$stage_total = count( $stage_plan );

		if ( $stage_index >= $stage_total ) {
			return self::build_progress( $run, $stage_total, $stage_total, '', STAGE_FINALISE, true );
		}

		$stage             = $stage_plan[ $stage_index ];
		$is_stage_finished = false;

		switch ( $stage->kind ) {
			case STAGE_PREPARE:
				$is_stage_finished = self::run_prepare_stage( $run, $stage );
				break;

			case STAGE_SEED:
				$is_stage_finished = self::run_seed_stage( $run, $spec );
				break;

			case STAGE_REFINE:
				$is_stage_finished = self::run_refine_stage( $run, $stage );
				break;

			case STAGE_FINALISE:
				$run->drop_scratch();
				$run->mark_complete();
				$is_stage_finished = true;
				break;

			default:
				throw new Run_Exception(
					esc_html__( 'The run contained an unrecognised stage and was stopped.', 'purge-user-accounts' )
				);
		}

		if ( $is_stage_finished && STAGE_FINALISE !== $stage->kind ) {
			$run->advance_stage();
		}

		$is_run_done = STAGE_FINALISE === $stage->kind && $is_stage_finished;

		return self::build_progress( $run, $run->get_stage_index(), $stage_total, $stage->label, $stage->kind, $is_run_done );
	}

	/**
	 * Materialise a scratch bucket for filters whose table lacks a user index.
	 *
	 * Single-shot: the whole set is written in one statement, because these are
	 * small relative to the user table on any site with a bot problem.
	 *
	 * @param Run   $run   Run being built.
	 * @param Stage $stage Stage being executed.
	 * @return bool Always true - the stage completes in one step.
	 * @throws Run_Exception When the source cannot be consulted.
	 */
	protected static function run_prepare_stage( Run $run, Stage $stage ): bool {
		global $wpdb;

		$filter = Integration_Registry::get_filter( $stage->filter_id );

		if ( null === $filter ) {
			throw new Run_Exception(
				esc_html__( 'A criterion vanished while the query was running, so it was stopped.', 'purge-user-accounts' )
			);
		}

		$scratch_queries = $filter->get_scratch_queries( $run->get_id(), (array) ( $stage->args['args'] ?? array() ) );

		foreach ( $scratch_queries as $scratch_query ) {
			if ( count( $scratch_query ) !== 2 ) {
				continue;
			}

			list( $scratch_sql, $scratch_params ) = $scratch_query;

			$prepared = empty( $scratch_params )
				? $scratch_sql
				: $wpdb->prepare( $scratch_sql, $scratch_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Filter supplies the placeholder string and its arguments.

			$result = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.

			// Fail closed. "No rows" and "could not ask" must never collapse:
			// a failed lookup would make every user look like they have nothing.
			if ( false === $result ) {
				throw new Run_Exception(
					sprintf(
						/* translators: %s: filter label. */
						esc_html__( 'The data needed for "%s" could not be read, so no users were selected. This prevents a failed lookup from silently matching everyone.', 'purge-user-accounts' ),
						esc_html( $filter->get_label() )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Insert candidate user IDs, one primary-key range at a time.
	 *
	 * The IDs go from MySQL into a MySQL table and never enter PHP. INSERT
	 * IGNORE makes replaying a chunk harmless, which is what lets a step be
	 * retried after a dropped connection.
	 *
	 * @param Run                            $run  Run being built.
	 * @param array<int,array<string,mixed>> $spec Normalised criteria.
	 * @return bool Whether the seed has reached the snapshot ceiling.
	 * @throws Run_Exception When the insert fails.
	 */
	protected static function run_seed_stage( Run $run, array $spec ): bool {
		global $wpdb;

		$cursor      = $run->get_stage_cursor();
		$max_user_id = $run->get_max_user_id();

		/**
		 * Filters how many user IDs the seed statement covers per step.
		 *
		 * @param int $chunk_size Default 25,000.
		 */
		$chunk_size = (int) apply_filters( 'hwpua_seed_chunk_size', DEF_SEED_CHUNK );

		// Floored only to stop a zero or negative value stalling the cursor.
		// A low floor lets an expensive filter pick a genuinely small chunk.
		$chunk_size  = max( self::MIN_CHUNK_SIZE, $chunk_size );
		$upper_bound = min( $max_user_id, $cursor + $chunk_size );

		$where_parts  = array( 'u.ID > %d', 'u.ID <= %d' );
		$where_params = array( $cursor, $upper_bound );

		$guarded_ids = Guards::get_excluded_user_ids();
		if ( ! empty( $guarded_ids ) ) {
			$placeholders  = implode( ',', array_fill( 0, count( $guarded_ids ), '%d' ) );
			$where_parts[] = "u.ID NOT IN ({$placeholders})";
			$where_params  = array_merge( $where_params, $guarded_ids );
		}

		foreach ( Run_Builder::get_sql_criteria( $spec ) as $criterion ) {
			$filter = Integration_Registry::get_filter( (string) $criterion['id'] );

			if ( null === $filter ) {
				continue;
			}

			$predicate                                = $filter->get_predicate( (array) $criterion['args'], $run->get_id() );
			list( $predicate_sql, $predicate_params ) = $predicate;

			if ( SENSE_HAS === $criterion['sense'] ) {
				$where_parts[] = "( {$predicate_sql} )";
			} else {
				$where_parts[] = "NOT ( {$predicate_sql} )";
			}

			$where_params = array_merge( $where_params, $predicate_params );
		}

		$run_items_table = Schema::table( TABLE_RUN_ITEMS );
		$where_sql       = implode( ' AND ', $where_parts );

		$seed_sql = "INSERT IGNORE INTO {$run_items_table} (run_id, user_id, matched_rule)
			SELECT %d, u.ID, NULL FROM {$wpdb->users} u WHERE {$where_sql}";

		$seed_params = array_merge( array( $run->get_id() ), $where_params );

		$result = $wpdb->query( $wpdb->prepare( $seed_sql, $seed_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders built above; every value is bound.

		if ( false === $result ) {
			throw new Run_Exception(
				esc_html__( 'Candidate users could not be selected, so the query was stopped rather than returning a partial answer.', 'purge-user-accounts' )
			);
		}

		$run->set_cursor( $upper_bound );

		$is_finished = $upper_bound >= $max_user_id;

		if ( $is_finished ) {
			$run->update( array( 'candidate_count' => $run->count_items() ) );
		}

		return $is_finished;
	}

	/**
	 * Narrow the run by one chunk of rows.
	 *
	 * Refinement only ever deletes. A stage interrupted halfway has produced a
	 * superset of the correct answer, so resuming converges and there is no
	 * partial state that yields a wrong-but-plausible result.
	 *
	 * @param Run   $run   Run being narrowed.
	 * @param Stage $stage Stage being executed.
	 * @return bool Whether the chunk was the last one.
	 * @throws Run_Exception When the chunk cannot be read or narrowed.
	 */
	protected static function run_refine_stage( Run $run, Stage $stage ): bool {
		global $wpdb;

		$filter = Integration_Registry::get_filter( $stage->filter_id );

		if ( null === $filter ) {
			throw new Run_Exception(
				esc_html__( 'A criterion vanished while the query was running, so it was stopped.', 'purge-user-accounts' )
			);
		}

		$cursor     = $run->get_stage_cursor();
		$chunk_size = max( self::MIN_CHUNK_SIZE, $filter->get_chunk_size() );
		$columns    = self::build_column_list( $filter->get_required_columns() );

		$run_items_table = Schema::table( TABLE_RUN_ITEMS );

		// Column list comes from build_column_list()'s allowlist and table names
		// from our own constants; every caller-supplied value is bound.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$chunk_sql = "SELECT {$columns} FROM {$run_items_table} ri
			JOIN {$wpdb->users} u ON u.ID = ri.user_id
			WHERE ri.run_id = %d AND ri.user_id > %d
			ORDER BY ri.user_id LIMIT %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results(
			$wpdb->prepare( $chunk_sql, $run->get_id(), $cursor, $chunk_size ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here with bound values.
		);

		if ( ! is_array( $rows ) ) {
			throw new Run_Exception(
				sprintf(
					/* translators: %s: filter label. */
					esc_html__( 'Users could not be read while applying "%s", so the query was stopped.', 'purge-user-accounts' ),
					esc_html( $filter->get_label() )
				)
			);
		}

		$is_finished = count( $rows ) < $chunk_size;

		if ( ! empty( $rows ) ) {
			$drop_ids   = $filter->evaluate_chunk( $rows, (array) ( $stage->args['args'] ?? array() ) );
			$drop_ids   = array_values( array_unique( array_map( 'absint', $drop_ids ) ) );
			$last_row   = end( $rows );
			$new_cursor = (int) $last_row->ID;

			// evaluate_chunk() returns the IDs that FAIL the filter's positive
			// condition - the drop list for sense `has`. Under `has_not` the
			// operator wants the opposite, so drop everything that passed.
			if ( SENSE_HAS_NOT === ( $stage->args['sense'] ?? SENSE_HAS_NOT ) ) {
				$drop_ids = self::invert_drop_list( $rows, $drop_ids );
			}

			if ( ! empty( $drop_ids ) ) {
				self::delete_items( $run->get_id(), $drop_ids, $filter->get_label() );
			}

			self::record_match_labels( $run->get_id(), $filter->get_last_match_labels(), $drop_ids );

			$run->set_cursor( $new_cursor );
		}

		return $is_finished;
	}

	/**
	 * Flip a drop list to everything in the chunk that was not dropped.
	 *
	 * @param array<int,object> $rows     Chunk rows.
	 * @param int[]             $drop_ids IDs the filter chose to drop.
	 * @return int[]
	 */
	protected static function invert_drop_list( array $rows, array $drop_ids ): array {
		$dropped_lookup = array_flip( $drop_ids );
		$inverted_ids   = array();

		foreach ( $rows as $row ) {
			$user_id = (int) $row->ID;

			if ( ! isset( $dropped_lookup[ $user_id ] ) ) {
				$inverted_ids[] = $user_id;
			}
		}

		return $inverted_ids;
	}

	/**
	 * Store why each surviving user matched.
	 *
	 * Written only for users still in the run - a dropped user has no reason to
	 * record. Not derivable later, which is why it is stored at match time.
	 *
	 * @param int               $run_id   Run identifier.
	 * @param array<int,string> $labels   User ID => label.
	 * @param int[]             $drop_ids Users removed from this chunk.
	 * @return void
	 */
	protected static function record_match_labels( int $run_id, array $labels, array $drop_ids ): void {
		global $wpdb;

		if ( empty( $labels ) ) {
			return;
		}

		$dropped_lookup  = array_flip( $drop_ids );
		$run_items_table = Schema::table( TABLE_RUN_ITEMS );

		foreach ( $labels as $user_id => $label ) {
			if ( isset( $dropped_lookup[ (int) $user_id ] ) ) {
				continue;
			}

			$updated = $wpdb->update(
				$run_items_table,
				array( 'matched_rule' => mb_substr( (string) $label, 0, 255 ) ),
				array(
					'run_id'  => $run_id,
					'user_id' => (int) $user_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);

			if ( false === $updated ) {
				hwpua_log_error( sprintf( 'Could not record the match reason for user %d in run %d.', (int) $user_id, $run_id ) );
			}
		}
	}

	/**
	 * Remove run items in one statement.
	 *
	 * @param int    $run_id       Run identifier.
	 * @param int[]  $user_ids     Users to remove.
	 * @param string $filter_label Label used in the failure message.
	 * @return void
	 * @throws Run_Exception When the delete fails.
	 */
	protected static function delete_items( int $run_id, array $user_ids, string $filter_label ): void {
		global $wpdb;

		$run_items_table = Schema::table( TABLE_RUN_ITEMS );
		$placeholders    = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$delete_params   = array_merge( array( $run_id ), $user_ids );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$run_items_table} WHERE run_id = %d AND user_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated from a counted array; every value is bound.
				$delete_params
			)
		);

		if ( false === $result ) {
			throw new Run_Exception(
				sprintf(
					/* translators: %s: filter label. */
					esc_html__( 'Users could not be narrowed while applying "%s", so the query was stopped rather than leaving an incorrect result.', 'purge-user-accounts' ),
					esc_html( $filter_label )
				)
			);
		}
	}

	/**
	 * Build a safe SELECT column list for a refine chunk.
	 *
	 * Requested columns are matched against an allowlist of user-table columns,
	 * because they reach SQL by name rather than as a bound value.
	 *
	 * @param string[] $requested_columns Columns a filter asked for.
	 * @return string
	 */
	protected static function build_column_list( array $requested_columns ): string {
		$allowed_columns = array(
			'user_login',
			'user_nicename',
			'user_email',
			'user_url',
			'user_registered',
			'display_name',
		);

		$selected_columns = array( 'u.ID' );

		foreach ( $requested_columns as $requested_column ) {
			if ( in_array( $requested_column, $allowed_columns, true ) ) {
				$selected_columns[] = 'u.' . $requested_column;
			} else {
				hwpua_log_error( sprintf( 'A filter requested the unknown user column "%s", which was ignored.', $requested_column ) );
			}
		}

		return implode( ', ', array_unique( $selected_columns ) );
	}

	/**
	 * Shape the progress payload returned to the browser or CLI.
	 *
	 * @param Run    $run         Run being advanced.
	 * @param int    $stage_index Current stage index.
	 * @param int    $stage_total Total stages.
	 * @param string $stage_label Description of the stage just executed.
	 * @param string $stage_kind  One of the STAGE_* constants.
	 * @param bool   $is_done     Whether the run has finished.
	 * @return array<string,mixed>
	 */
	protected static function build_progress( Run $run, int $stage_index, int $stage_total, string $stage_label, string $stage_kind, bool $is_done ): array {
		$percent = $stage_total > 0 ? (int) floor( ( min( $stage_index, $stage_total ) / $stage_total ) * 100 ) : 100;

		return array(
			'run_id'        => $run->get_id(),
			'stage_index'   => $stage_index,
			'stage_total'   => $stage_total,
			'stage_label'   => $stage_label,
			'stage_kind'    => $stage_kind,
			'progress_pct'  => $is_done ? 100 : $percent,
			'current_count' => $run->count_items(),
			'done'          => $is_done,
			'aborted'       => false,
		);
	}
}
