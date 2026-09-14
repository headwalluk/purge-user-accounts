<?php
/**
 * Base class for selection criteria.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * A single selection criterion, supplied by an integration.
 *
 * Contributes in exactly one of two ways:
 *
 *  - KIND_SQL    - a predicate folded into the seed statement, optionally
 *                  backed by a pre-materialised scratch bucket.
 *  - KIND_REFINE - a callback that receives a CHUNK of rows and returns the
 *                  IDs to drop.
 *
 * There is deliberately no per-user callback. Per-user iteration hydrates rows
 * at roughly 52 MB per 100,000 (measured) and issues N queries per filter,
 * forfeiting the INSERT ... SELECT seed the memory design rests on. See
 * dev-notes/12-integration-framework.md §3.
 */
abstract class Filter {

	const KIND_SQL    = 'sql';
	const KIND_REFINE = 'refine';

	/**
	 * Namespaced identifier, e.g. 'wp-core.no-content'.
	 */
	abstract public function get_id(): string;

	/**
	 * Human-readable name for the UI.
	 */
	abstract public function get_label(): string;

	/**
	 * UI grouping key. Integrations are not UI groups.
	 */
	public function get_group(): string {
		return 'general';
	}

	/**
	 * Whether this contributes SQL or a chunked callback.
	 */
	public function get_kind(): string {
		return self::KIND_SQL;
	}

	/**
	 * Whether the criterion can be used on this installation.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Why the criterion is unavailable, for the disabled control.
	 */
	public function get_unavailable_reason(): string {
		return '';
	}

	/**
	 * Whether `has` and `has_not` are both meaningful.
	 *
	 * This is how positive evidence enters a query. Nothing silently removes
	 * users from a result set; if the operator wants to keep customers they say
	 * so in the query, where it shows in the criteria list, count and export.
	 */
	public function supports_sense(): bool {
		return true;
	}

	/**
	 * Why the arguments cannot express a usable criterion, or '' when they can.
	 *
	 * Unusable arguments match nobody, which `has_not` turns into everybody, so
	 * Run_Builder refuses the criterion instead.
	 *
	 * @param array<string,mixed> $args Operator-supplied arguments.
	 */
	public function get_argument_error( array $args ): string {
		unset( $args );

		return '';
	}

	/**
	 * Scratch bucket this filter needs materialised first, or '' for none.
	 */
	public function get_scratch_bucket(): string {
		return '';
	}

	/**
	 * The statements that fill this filter's scratch bucket.
	 *
	 * A list, because one bucket may legitimately need several passes: the
	 * WooCommerce customer set is built from orders linked by customer ID AND
	 * from guest orders matched on the billing address, which cannot sensibly
	 * be one statement.
	 *
	 * @param int                 $run_id Run being built.
	 * @param array<string,mixed> $args   Operator-supplied arguments.
	 * @return array<int,array{0:string,1:array<int,mixed>}>
	 */
	public function get_scratch_queries( int $run_id, array $args ): array {
		unset( $run_id, $args );

		return array();
	}

	/**
	 * A WHERE fragment for the seed statement, with its bind parameters.
	 *
	 * The fragment must reference the users table as `u` and must be safe to
	 * wrap in NOT( ... ) for the inverted sense.
	 *
	 * @param array<string,mixed> $args   Operator-supplied arguments.
	 * @param int                 $run_id Run being built, for scoping a scratch join.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		unset( $args, $run_id );

		return array( '1=1', array() );
	}

	/**
	 * Rows per chunk for a refine filter.
	 */
	public function get_chunk_size(): int {
		return DEF_REFINE_CHUNK;
	}

	/**
	 * Columns a refine filter needs loaded from the users table.
	 *
	 * @return string[]
	 */
	public function get_required_columns(): array {
		return array();
	}

	/**
	 * Decide which users in a chunk fail this criterion.
	 *
	 * Returns the IDs that FAIL this filter's positive condition - that is, the
	 * drop list for sense `has`. The engine inverts it for `has_not`, so an
	 * implementation never reasons about sense itself.
	 *
	 * Always a drop list, never a keep list: the narrowing invariant depends on
	 * refinement only ever removing rows, and being explicit about the direction
	 * stops anyone accidentally flipping it.
	 *
	 * @param array<int,object>   $rows Rows with ID plus the required columns.
	 * @param array<string,mixed> $args Operator-supplied arguments.
	 * @return int[]
	 */
	public function evaluate_chunk( array $rows, array $args ): array {
		unset( $rows, $args );

		return array();
	}

	/**
	 * Why each surviving user in the last chunk matched.
	 *
	 * Populated by `evaluate_chunk()` where a filter can say something more
	 * useful than its own name - the pattern ruleset reports which rule fired.
	 * The engine writes these into `hwpua_run_items.matched_rule`, which is what
	 * makes the "why did this match?" column possible and turns tens of
	 * thousands of unreviewable rows into a dozen reviewable groups.
	 *
	 * @return array<int,string> User ID => label.
	 */
	public function get_last_match_labels(): array {
		return array();
	}
}
