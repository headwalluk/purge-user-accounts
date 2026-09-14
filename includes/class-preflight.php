<?php
/**
 * What the operator is told before a destructive action runs.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Builds the pre-flight checklist for one action against one run.
 *
 * Every line is computed at confirmation time by a real query. None of it is
 * boilerplate, and a warning only appears when it is actually true of THIS run
 * - otherwise operators learn to scroll past it.
 */
class Preflight {

	const LEVEL_OK    = 'ok';
	const LEVEL_WARN  = 'warn';
	const LEVEL_BLOCK = 'block';

	/**
	 * Assemble the checklist.
	 *
	 * @param Run                 $run    Run to act on.
	 * @param Action              $action Action to apply.
	 * @param array<string,mixed> $args   Operator-supplied arguments.
	 * @return array<string,mixed>
	 */
	public static function build( Run $run, Action $action, array $args ): array {
		$checks    = array();
		$is_usable = true;

		foreach (
			array(
				self::check_run_usable( $run ),
				self::check_export( $run, $action ),
				self::check_guards( $run, $args ),
				self::check_criteria_count( $run, $action ),
				self::check_proportion( $run, $action ),
				self::check_order_history( $run, $action ),
				self::check_reassign( $action, $args ),
			) as $check
		) {
			if ( null === $check ) {
				continue;
			}

			$checks[] = $check;

			if ( self::LEVEL_BLOCK === $check['level'] ) {
				$is_usable = false;
			}
		}

		$estimate_seconds = Job_Runner::estimate_seconds( $action, $run->get_matched_count() );

		return array(
			'run_id'             => $run->get_id(),
			'action'             => $action->get_id(),
			'action_label'       => $action->get_label(),
			'description'        => $action->get_description(),
			'matched'            => $run->get_matched_count(),
			'reversible'         => $action->is_reversible(),
			'destructiveness'    => $action->get_destructiveness(),
			'confirmation'       => $action->get_confirmation_phrase( $run ),
			'needs_confirmation' => $action->get_destructiveness() >= 50,
			'estimate_seconds'   => $estimate_seconds,
			'estimate_text'      => self::describe_duration( $estimate_seconds ),
			'checks'             => $checks,
			'can_proceed'        => $is_usable,
		);
	}

	/**
	 * One checklist line.
	 *
	 * @param string $level One of the LEVEL_* constants.
	 * @param string $text  What to tell the operator.
	 * @return array<string,string>
	 */
	protected static function line( string $level, string $text ): array {
		return array(
			'level' => $level,
			'text'  => $text,
		);
	}

	/**
	 * The run must be finished and fresh.
	 *
	 * @param Run $run Run to act on.
	 * @return array<string,string>|null
	 */
	protected static function check_run_usable( Run $run ): ?array {
		if ( ! $run->is_complete() ) {
			return self::line( self::LEVEL_BLOCK, __( 'This query did not finish. An incomplete result is not a result.', 'purge-user-accounts' ) );
		}

		if ( $run->is_stale() ) {
			return self::line(
				self::LEVEL_BLOCK,
				__( 'This result is more than a day old. People register, sign in and buy things in the meantime — re-run the query first.', 'purge-user-accounts' )
			);
		}

		return self::line( self::LEVEL_OK, __( 'Result is complete and recent.', 'purge-user-accounts' ) );
	}

	/**
	 * A destructive action needs the operator to hold the list.
	 *
	 * @param Run    $run    Run to act on.
	 * @param Action $action Action to apply.
	 * @return array<string,string>|null
	 */
	protected static function check_export( Run $run, Action $action ): ?array {
		if ( ! $action->requires_export() ) {
			return null;
		}

		$export_path = Export_Directory::find_latest_for_run( $run->get_id() );

		if ( '' === $export_path ) {
			return self::line(
				self::LEVEL_BLOCK,
				__( 'Download the CSV first. Once these accounts are changed, that file is the only record of who was affected — and the plugin keeps no copy of its own.', 'purge-user-accounts' )
			);
		}

		return self::line(
			self::LEVEL_OK,
			sprintf(
				/* translators: %s: human-readable time difference. */
				__( 'Export taken %s ago.', 'purge-user-accounts' ),
				human_time_diff( (int) filemtime( $export_path ) )
			)
		);
	}

	/**
	 * How many of the matched users the guards will refuse to touch.
	 *
	 * @param Run                 $run  Run to act on.
	 * @param array<string,mixed> $args Operator-supplied arguments.
	 * @return array<string,string>|null
	 */
	protected static function check_guards( Run $run, array $args ): ?array {
		global $wpdb;

		$items_table = Schema::table( TABLE_RUN_ITEMS );

		// Bounded sample: enough to report honestly without scanning a 40,000
		// row result at confirmation time.
		$sample_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$items_table} WHERE run_id = %d ORDER BY user_id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from our own constant.
				$run->get_id(),
				5000
			)
		);

		if ( ! is_array( $sample_ids ) || empty( $sample_ids ) ) {
			return null;
		}

		$allow_privileged = ! empty( $args['allow_privileged'] );
		$protected        = Guards::find_protected( array_map( 'absint', $sample_ids ), $allow_privileged );

		if ( empty( $protected ) ) {
			return self::line( self::LEVEL_OK, __( 'No protected accounts in this result.', 'purge-user-accounts' ) );
		}

		return self::line(
			self::LEVEL_OK,
			sprintf(
				/* translators: %s: number of accounts. */
				_n(
					'%s protected account will be skipped automatically.',
					'%s protected accounts will be skipped automatically.',
					count( $protected ),
					'purge-user-accounts'
				),
				number_format_i18n( count( $protected ) )
			)
		);
	}

	/**
	 * Combination is what makes a match meaningful.
	 *
	 * A single criterion describes a population, not a conclusion. "Is a
	 * subscriber" is most of a site's legitimate members.
	 *
	 * @param Run    $run    Run to act on.
	 * @param Action $action Action to apply.
	 * @return array<string,string>|null
	 */
	protected static function check_criteria_count( Run $run, Action $action ): ?array {
		if ( $action->get_destructiveness() < 50 ) {
			return null;
		}

		$criteria_count = count( $run->get_filter_spec() );

		/**
		 * Filters how many criteria a destructive action expects.
		 *
		 * @param int $minimum Default two.
		 */
		$minimum = (int) apply_filters( 'hwpua_minimum_criteria', 2 );

		if ( $criteria_count >= $minimum ) {
			return null;
		}

		return self::line(
			self::LEVEL_WARN,
			sprintf(
				/* translators: %d: number of criteria. */
				_n(
					'This result rests on only %d criterion. A single criterion describes a population, not a conclusion — combining several is what makes a match meaningful.',
					'This result rests on only %d criteria. Combining more of them is what makes a match meaningful.',
					$criteria_count,
					'purge-user-accounts'
				),
				$criteria_count
			)
		);
	}

	/**
	 * A circuit-breaker on the share of the site being acted upon.
	 *
	 * Proportion, not an absolute: 40,000 accounts is routine on a large site
	 * and catastrophic on a small one.
	 *
	 * @param Run    $run    Run to act on.
	 * @param Action $action Action to apply.
	 * @return array<string,string>|null
	 */
	protected static function check_proportion( Run $run, Action $action ): ?array {
		if ( $action->get_destructiveness() < 50 ) {
			return null;
		}

		$total = Run_History::count_users();

		if ( $total < 1 ) {
			return null;
		}

		$share = $run->get_matched_count() / $total;

		/**
		 * Filters the share of a site's users that triggers a warning.
		 *
		 * @param float $threshold Default half.
		 */
		$threshold = (float) apply_filters( 'hwpua_proportion_warning', 0.5 );

		if ( $share < $threshold ) {
			return null;
		}

		return self::line(
			self::LEVEL_WARN,
			sprintf(
				/* translators: 1: percentage, 2: matched count, 3: total users. */
				__( 'This is %1$s of every account on the site — %2$s of %3$s. That may be right for a site overrun with signups, but check the result is not simply too broad.', 'purge-user-accounts' ),
				round( $share * 100 ) . '%',
				number_format_i18n( $run->get_matched_count() ),
				number_format_i18n( $total )
			)
		);
	}

	/**
	 * Whether deleting would orphan WooCommerce orders.
	 *
	 * @param Run    $run    Run to act on.
	 * @param Action $action Action to apply.
	 * @return array<string,string>|null
	 */
	protected static function check_order_history( Run $run, Action $action ): ?array {
		global $wpdb;

		if ( 'delete' !== $action->get_id() || ! Environment::has_woocommerce() ) {
			return null;
		}

		$items_table = Schema::table( TABLE_RUN_ITEMS );

		if ( Environment::uses_hpos() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$count_sql    = "SELECT COUNT(DISTINCT o.customer_id) FROM {$orders_table} o
				JOIN {$items_table} ri ON ri.user_id = o.customer_id AND ri.run_id = %d
				WHERE o.customer_id > 0";
		} else {
			$count_sql = "SELECT COUNT(DISTINCT pm.meta_value) FROM {$wpdb->postmeta} pm
				JOIN {$items_table} ri ON ri.user_id = CAST(pm.meta_value AS UNSIGNED) AND ri.run_id = %d
				WHERE pm.meta_key = '_customer_user' AND pm.meta_value > 0";
		}

		$with_orders = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $run->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants; the run id is bound.

		if ( 0 === $with_orders ) {
			return self::line( self::LEVEL_OK, __( 'None of these accounts has WooCommerce order history.', 'purge-user-accounts' ) );
		}

		return self::line(
			self::LEVEL_WARN,
			sprintf(
				/* translators: %s: number of accounts. */
				_n(
					'%s of these accounts has WooCommerce order history. Deleting it will leave those orders without a customer.',
					'%s of these accounts have WooCommerce order history. Deleting them will leave those orders without a customer.',
					$with_orders,
					'purge-user-accounts'
				),
				number_format_i18n( $with_orders )
			)
		);
	}

	/**
	 * Deleting requires a decision about the users' content.
	 *
	 * @param Action              $action Action to apply.
	 * @param array<string,mixed> $args   Operator-supplied arguments.
	 * @return array<string,string>|null
	 */
	protected static function check_reassign( Action $action, array $args ): ?array {
		if ( 'delete' !== $action->get_id() ) {
			return null;
		}

		if ( ! array_key_exists( 'reassign_to', $args ) ) {
			return self::line( self::LEVEL_BLOCK, __( 'Choose what happens to any content these accounts authored.', 'purge-user-accounts' ) );
		}

		$reassign_to = absint( $args['reassign_to'] );

		if ( $reassign_to > 0 ) {
			$owner = get_userdata( $reassign_to );

			return self::line(
				self::LEVEL_OK,
				sprintf(
					/* translators: %s: user login. */
					__( 'Their content will be reassigned to %s.', 'purge-user-accounts' ),
					$owner instanceof \WP_User ? $owner->user_login : (string) $reassign_to
				)
			);
		}

		return self::line( self::LEVEL_WARN, __( 'Their content will be deleted along with the accounts.', 'purge-user-accounts' ) );
	}

	/**
	 * Human wording for a duration.
	 *
	 * @param int $seconds Estimated seconds.
	 * @return string
	 */
	public static function describe_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return __( 'a few seconds', 'purge-user-accounts' );
		}

		if ( $seconds < 3600 ) {
			return sprintf(
				/* translators: %d: minutes. */
				_n( 'about %d minute', 'about %d minutes', (int) ceil( $seconds / 60 ), 'purge-user-accounts' ),
				(int) ceil( $seconds / 60 )
			);
		}

		return sprintf(
			/* translators: %s: hours, to one decimal place. */
			__( 'about %s hours', 'purge-user-accounts' ),
			number_format_i18n( $seconds / 3600, 1 )
		);
	}
}
