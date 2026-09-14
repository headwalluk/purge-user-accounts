<?php
/**
 * WordPress core integration.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Holds at least one of the named roles.
 */
class Wp_Core_Role_Filter extends Filter {

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.role';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Role', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'roles';
	}

	/**
	 * At least one role must be named.
	 *
	 * @param array<string,mixed> $args Operator-supplied arguments.
	 */
	public function get_argument_error( array $args ): string {
		$role_slugs = isset( $args['roles'] ) && is_array( $args['roles'] ) ? array_filter( array_map( 'sanitize_key', $args['roles'] ) ) : array();

		return empty( $role_slugs ) ? __( 'tick at least one role.', 'purge-user-accounts' ) : '';
	}

	/**
	 * Matches when the user holds any of the named roles.
	 *
	 * The quotes in the LIKE pattern matter: without them `subscriber` also
	 * matches a custom `subscriber_plus` role.
	 *
	 * @param array<string,mixed> $args   Expects `roles` as an array of slugs.
	 * @param int                 $run_id Unused.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		global $wpdb;

		unset( $run_id );

		$role_slugs = isset( $args['roles'] ) && is_array( $args['roles'] ) ? $args['roles'] : array();
		$role_slugs = array_values( array_filter( array_map( 'sanitize_key', $role_slugs ) ) );

		if ( empty( $role_slugs ) ) {
			// No roles named means the criterion cannot match anything. Say so
			// in SQL rather than quietly matching everyone.
			return array( '1=0', array() );
		}

		$like_parts  = array();
		$like_params = array( Environment::get_capabilities_meta_key() );

		foreach ( $role_slugs as $role_slug ) {
			$like_parts[]  = 'um.meta_value LIKE %s';
			$like_params[] = '%' . $wpdb->esc_like( '"' . $role_slug . '"' ) . '%';
		}

		$predicate = "EXISTS (
			SELECT 1 FROM {$wpdb->usermeta} um
			WHERE um.user_id = u.ID AND um.meta_key = %s AND ( " . implode( ' OR ', $like_parts ) . ' )
		)';

		return array( $predicate, $like_params );
	}
}

/**
 * Holds any role at all.
 *
 * Used with sense `has_not` to find users with no capabilities row, or an empty
 * one. They are invisible to role-set filtering, common among bulk bot signups,
 * and every role-based tool misses them.
 */
class Wp_Core_Any_Role_Filter extends Filter {

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.any-role';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Has any role', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'roles';
	}

	/**
	 * Matches when a non-empty capabilities row exists.
	 *
	 * @param array<string,mixed> $args   Unused.
	 * @param int                 $run_id Unused.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		global $wpdb;

		unset( $args, $run_id );

		$predicate = "EXISTS (
			SELECT 1 FROM {$wpdb->usermeta} um
			WHERE um.user_id = u.ID AND um.meta_key = %s
			  AND um.meta_value != %s AND um.meta_value != %s
		)";

		return array(
			$predicate,
			array( Environment::get_capabilities_meta_key(), 'a:0:{}', '' ),
		);
	}
}

/**
 * Has authored content.
 */
class Wp_Core_Content_Filter extends Filter {

	/**
	 * Post statuses counted as content by default.
	 *
	 * `auto-draft` is excluded deliberately: visiting the new-post screen once
	 * creates one, and counting it would spare accounts that contributed nothing.
	 *
	 * @var string[]
	 */
	const DEFAULT_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.content';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Has published content', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'content';
	}

	/**
	 * At least one post type and one status must remain.
	 *
	 * @param array<string,mixed> $args Operator-supplied arguments.
	 */
	public function get_argument_error( array $args ): string {
		$post_types    = isset( $args['post_types'] ) && is_array( $args['post_types'] ) ? $args['post_types'] : array( 'post', 'page' );
		$post_statuses = isset( $args['post_statuses'] ) && is_array( $args['post_statuses'] ) ? $args['post_statuses'] : self::DEFAULT_STATUSES;
		$post_types    = array_diff( array_filter( array_map( 'sanitize_key', $post_types ) ), array( 'revision' ) );
		$post_statuses = array_filter( array_map( 'sanitize_key', $post_statuses ) );

		return empty( $post_types ) || empty( $post_statuses ) ? __( 'choose at least one post type and one status.', 'purge-user-accounts' ) : '';
	}

	/**
	 * Matches when the user authored a post of the given types and statuses.
	 *
	 * Attachments are excluded by default - an avatar upload is not authorship -
	 * and revisions must never be included.
	 *
	 * @param array<string,mixed> $args   Optional `post_types` and `post_statuses`.
	 * @param int                 $run_id Unused.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		global $wpdb;

		unset( $run_id );

		$post_types = isset( $args['post_types'] ) && is_array( $args['post_types'] )
			? $args['post_types']
			: array( 'post', 'page' );

		$post_statuses = isset( $args['post_statuses'] ) && is_array( $args['post_statuses'] )
			? $args['post_statuses']
			: self::DEFAULT_STATUSES;

		$post_types    = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		$post_statuses = array_values( array_filter( array_map( 'sanitize_key', $post_statuses ) ) );

		// A revision is never authorship, whatever the operator selected.
		$post_types = array_values( array_diff( $post_types, array( 'revision' ) ) );

		if ( empty( $post_types ) || empty( $post_statuses ) ) {
			return array( '1=0', array() );
		}

		$type_placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );

		$predicate = "EXISTS (
			SELECT 1 FROM {$wpdb->posts} p
			WHERE p.post_author = u.ID
			  AND p.post_type IN ({$type_placeholders})
			  AND p.post_status IN ({$status_placeholders})
		)";

		return array( $predicate, array_merge( $post_types, $post_statuses ) );
	}
}

/**
 * Has left comments.
 *
 * `wp_comments` carries no index on `user_id`, so a correlated NOT EXISTS would
 * re-materialise the whole table on every seed chunk - measured at 30x slower
 * across 41 chunks. The commenter set is materialised once instead. See
 * dev-notes/reference/benchmarks-m3-2026-09-12.md §3.
 */
class Wp_Core_Comments_Filter extends Filter {

	/**
	 * Scratch bucket name.
	 */
	public function get_scratch_bucket(): string {
		return 'commenter';
	}

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.comments';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Has comments', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'content';
	}

	/**
	 * Materialise every user who has left a comment that was not spam.
	 *
	 * Spam and trashed comments are excluded, so an account whose only comments
	 * were spam still counts as having contributed nothing.
	 *
	 * @param int                 $run_id Run being built.
	 * @param array<string,mixed> $args   Operator-supplied arguments.
	 * @return array<int,array{0:string,1:array<int,mixed>}>
	 */
	public function get_scratch_queries( int $run_id, array $args ): array {
		global $wpdb;

		unset( $args );

		$scratch_table = Schema::table( TABLE_RUN_SCRATCH );

		$scratch_sql = "INSERT IGNORE INTO {$scratch_table} (run_id, bucket, user_id)
			SELECT %d, 'commenter', c.user_id
			FROM {$wpdb->comments} c
			WHERE c.user_id > 0 AND c.comment_approved NOT IN ('spam', 'trash')
			GROUP BY c.user_id";

		return array( array( $scratch_sql, array( $run_id ) ) );
	}

	/**
	 * Matches when the user appears in the materialised commenter set.
	 *
	 * @param array<string,mixed> $args   Unused.
	 * @param int                 $run_id Run being built.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		unset( $args );

		$scratch_table = Schema::table( TABLE_RUN_SCRATCH );

		$predicate = "EXISTS (
			SELECT 1 FROM {$scratch_table} s
			WHERE s.run_id = %d AND s.bucket = 'commenter' AND s.user_id = u.ID
		)";

		return array( $predicate, array( $run_id ) );
	}
}

/**
 * Registered before or after a date.
 *
 * Mostly a safety criterion rather than a selection one: "registered more than
 * 90 days ago" protects a legitimate new signup who has not yet had time to log
 * in, comment or buy anything.
 */
class Wp_Core_Registered_Filter extends Filter {

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.registered';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Registration date', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'registration';
	}

	/**
	 * Direction is expressed in arguments, so sense would be ambiguous.
	 */
	public function supports_sense(): bool {
		return false;
	}

	/**
	 * A usable cutoff is required.
	 *
	 * @param array<string,mixed> $args Operator-supplied arguments.
	 */
	public function get_argument_error( array $args ): string {
		return '' === hwpua_resolve_cutoff( $args ) ? __( 'give a number of days or a date.', 'purge-user-accounts' ) : '';
	}

	/**
	 * Matches on registration date, either side of a cutoff.
	 *
	 * @param array<string,mixed> $args   `direction` of before|after, and either
	 *                                    `date` (Y-m-d) or `days_ago`.
	 * @param int                 $run_id Unused.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		unset( $run_id );

		$direction = isset( $args['direction'] ) && 'after' === $args['direction'] ? 'after' : 'before';
		$cutoff    = hwpua_resolve_cutoff( $args );

		if ( '' === $cutoff ) {
			return array( '1=0', array() );
		}

		$comparison = 'after' === $direction ? '>' : '<';

		return array( "u.user_registered {$comparison} %s", array( $cutoff ) );
	}
}

/**
 * Email or username matches a known bad-signup pattern.
 *
 * The only refine-kind filter in WP Core: PCRE semantics differ enough between
 * MariaDB, MySQL 5.7 and MySQL 8 that pushing 33 patterns into SQL REGEXP would
 * be both unportable and a full scan with 33 evaluations per row.
 *
 * Matching itself is nearly free - measured at 0.1 s for 100,000 users across
 * the whole ruleset. The cost of this filter is entirely the MySQL row fetch,
 * which is why the chunk is generous.
 */
class Wp_Core_Bad_Signup_Filter extends Filter {

	/**
	 * Labels for the users matched in the last chunk.
	 *
	 * @var array<int,string>
	 */
	protected array $last_match_labels = array();

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.bad-signup-pattern';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Matches a bad-signup pattern', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'patterns';
	}

	/**
	 * Evaluated in PHP rather than SQL.
	 */
	public function get_kind(): string {
		return self::KIND_REFINE;
	}

	/**
	 * Fetch-bound rather than CPU-bound, so the chunk can be generous.
	 */
	public function get_chunk_size(): int {
		return DEF_REFINE_CHUNK;
	}

	/**
	 * Columns needed to compose the match line.
	 *
	 * @return string[]
	 */
	public function get_required_columns(): array {
		return array( 'user_login', 'user_email' );
	}

	/**
	 * Test each row against the ruleset.
	 *
	 * Returns the IDs that match NO rule - the drop list for sense `has`.
	 *
	 * @param array<int,object>   $rows Chunk rows.
	 * @param array<string,mixed> $args Optional `use_allowlist`, default true.
	 * @return int[]
	 */
	public function evaluate_chunk( array $rows, array $args ): array {
		$this->last_match_labels = array();

		$use_allowlist = ! isset( $args['use_allowlist'] ) || (bool) $args['use_allowlist'];
		$drop_ids      = array();

		foreach ( $rows as $row ) {
			$user_id = (int) $row->ID;

			// The allowlist suppresses pattern matches only. It does not spare
			// users from any other criterion.
			if ( $use_allowlist && Domain_Allowlist::is_allowed( (string) $row->user_email ) ) {
				$drop_ids[] = $user_id;
				continue;
			}

			$composed = Pattern_Ruleset::compose_line( $user_id, (string) $row->user_login, (string) $row->user_email );
			$rule     = Pattern_Ruleset::match_line( $composed );

			if ( null === $rule ) {
				$drop_ids[] = $user_id;
			} else {
				$this->last_match_labels[ $user_id ] = $rule['label'];
			}
		}

		return $drop_ids;
	}

	/**
	 * Which rule matched each surviving user in the last chunk.
	 *
	 * @return array<int,string>
	 */
	public function get_last_match_labels(): array {
		return $this->last_match_labels;
	}
}

/**
 * Login or email local part looks machine-generated.
 *
 * Structural rather than list-based, so it catches generated identifiers the
 * curated ruleset has never seen and needs no list to maintain. Technique
 * adapted from the Resus Room User Audit plugin (GPL-2.0-or-later) - see
 * dev-notes/reference/prior-art-resus-room-user-audit.md §2.5.
 *
 * Thresholds are deliberately conservative so real names are safe.
 */
class Wp_Core_Random_Identity_Filter extends Filter {

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.random-identity';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Username or email looks machine-generated', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'patterns';
	}

	/**
	 * Evaluated in PHP rather than SQL.
	 */
	public function get_kind(): string {
		return self::KIND_REFINE;
	}

	/**
	 * Columns needed to inspect the identity.
	 *
	 * @return string[]
	 */
	public function get_required_columns(): array {
		return array( 'user_login', 'user_email' );
	}

	/**
	 * Drop rows whose login and email local part both look human.
	 *
	 * @param array<int,object>   $rows Chunk rows.
	 * @param array<string,mixed> $args Unused.
	 * @return int[]
	 */
	public function evaluate_chunk( array $rows, array $args ): array {
		unset( $args );

		$drop_ids = array();

		foreach ( $rows as $row ) {
			$email_local = strstr( (string) $row->user_email, '@', true );
			$email_local = false === $email_local ? '' : $email_local;

			if ( ! self::looks_generated( (string) $row->user_login ) && ! self::looks_generated( $email_local ) ) {
				$drop_ids[] = (int) $row->ID;
			}
		}

		return $drop_ids;
	}

	/**
	 * Three complementary shapes that human names do not take.
	 *
	 * @param string $value Login or email local part.
	 * @return bool
	 */
	public static function looks_generated( string $value ): bool {
		// Case is tested first, because the checks below destroy it.
		$cased    = (string) preg_replace( '/[^A-Za-z0-9]/', '', $value );
		$stripped = strtolower( $cased );
		$length   = strlen( $stripped );
		$digits   = preg_match_all( '/[0-9]/', $stripped );
		$letters  = preg_match_all( '/[a-z]/', $stripped );
		$verdict  = false;

		if ( self::has_scattered_case( $cased ) ) {
			// Randomised capitalisation: CwAmFdRDNWyM, mPpAWXfxrbqIsTj.
			$verdict = true;
		} elseif ( $length >= 18 && $letters > 0 && $digits > 0 && ( $digits / $length ) >= 0.35 ) {
			// Long, mixed and digit-heavy.
			$verdict = true;
		} elseif ( $length >= 16 && $letters >= 12 && ! preg_match( '/[aeiouy]/', $stripped ) ) {
			// Long and vowel-free.
			$verdict = true;
		} elseif ( preg_match( '/^(?:[a-z]{1,3}[0-9]{4,}){2,}$/', $stripped ) ) {
			// Repeated letter-block/digit-block structure.
			$verdict = true;
		}

		return $verdict;
	}

	/**
	 * Whether capitalisation is scattered rather than word-initial.
	 *
	 * Corpus-tested 13 September 2026 against 38,281 live accounts. Requiring
	 * merely "contains a lowercase-to-uppercase transition" flagged 5,050 users
	 * at a 4.87% false-positive rate - it catches every CamelCase name, and real
	 * paramedics register as `KarisDuffy` and `SeanFlynn`.
	 *
	 * Requiring THREE such transitions flagged 2,124 with **3** false positives,
	 * or 0.14%. A name concatenates a few words - `JonGill` has one transition,
	 * `HannahRogersSmithSWAST` has four and is one of the three. A generated
	 * string changes case constantly.
	 *
	 * @param string $value Alphanumeric-only value, case preserved.
	 * @return bool
	 */
	protected static function has_scattered_case( string $value ): bool {
		if ( strlen( $value ) < 5 || 1 !== preg_match( '/^[A-Za-z]+$/', $value ) ) {
			return false;
		}

		return 1 === preg_match( '/[a-z][A-Z].*[a-z][A-Z].*[a-z][A-Z]/', $value );
	}
}

/**
 * Has a login record.
 *
 * Used with sense `has_not` to find users nothing has ever seen sign in.
 *
 * This is NOT "never logged in". It is "no record of a login", and the two
 * differ whenever the data source started recording after the account was
 * created - which is the normal case. The `include_unknown` argument is the
 * difference between a safe query and a destructive one.
 */
class Wp_Core_Login_Record_Filter extends Filter {

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.login-record';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Has a login record', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'login';
	}

	/**
	 * Needs a detected source.
	 */
	public function is_available(): bool {
		return Last_Login::has_source();
	}

	/**
	 * Why the criterion cannot be used here.
	 */
	public function get_unavailable_reason(): string {
		return Last_Login::get_unavailable_reason();
	}

	/**
	 * Matches when the active source holds a record for the user.
	 *
	 * With `include_unknown` false - the default - users who registered before
	 * the source began recording are treated as HAVING a record, so that
	 * `has_not` cannot return them. They are unknown, not absent, and the safe
	 * reading is to leave them alone.
	 *
	 * @param array<string,mixed> $args   Optional `include_unknown`, default false.
	 * @param int                 $run_id Unused.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		global $wpdb;

		unset( $run_id );

		$source = Last_Login::get_active_source();

		if ( null === $source ) {
			return array( '1=0', array() );
		}

		$has_record = "EXISTS (
			SELECT 1 FROM {$wpdb->usermeta} llm
			WHERE llm.user_id = u.ID AND llm.meta_key = %s AND llm.meta_value != ''
		)";

		$include_unknown = isset( $args['include_unknown'] ) && (bool) $args['include_unknown'];

		if ( $include_unknown ) {
			return array( $has_record, array( $source->meta_key ) );
		}

		$coverage = Last_Login::get_coverage();
		$earliest = null === $coverage ? '' : $coverage->earliest_record;

		if ( '' === $earliest ) {
			// Nothing recorded yet: every user is unknown, so a `has_not` query
			// must return nobody rather than everybody.
			return array( '1=1', array() );
		}

		// Registered before the source began counts as having a record, which
		// keeps the unknown cohort out of a `has_not` result.
		return array(
			"( {$has_record} OR u.user_registered < %s )",
			array( $source->meta_key, $earliest ),
		);
	}
}

/**
 * Has been seen since a given date.
 *
 * Used with sense `has_not` for "has not logged in for X". Carries the same
 * unknown-cohort protection as the record filter.
 */
class Wp_Core_Active_Since_Filter extends Filter {

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'wp-core.active-since';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Seen since', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'login';
	}

	/**
	 * Needs a detected source.
	 */
	public function is_available(): bool {
		return Last_Login::has_source();
	}

	/**
	 * Why the criterion cannot be used here.
	 */
	public function get_unavailable_reason(): string {
		return Last_Login::get_unavailable_reason();
	}

	/**
	 * A usable cutoff is required.
	 *
	 * @param array<string,mixed> $args Operator-supplied arguments.
	 */
	public function get_argument_error( array $args ): string {
		return '' === hwpua_resolve_cutoff( $args ) ? __( 'give a number of days or a date.', 'purge-user-accounts' ) : '';
	}

	/**
	 * Matches when the user has a record newer than the cutoff.
	 *
	 * @param array<string,mixed> $args   `days_ago` or `date`, plus optional
	 *                                    `include_unknown`.
	 * @param int                 $run_id Unused.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		global $wpdb;

		unset( $run_id );

		$source = Last_Login::get_active_source();

		if ( null === $source ) {
			return array( '1=0', array() );
		}

		$cutoff = hwpua_resolve_cutoff( $args );

		if ( '' === $cutoff ) {
			return array( '1=0', array() );
		}

		$datetime_expression = $source->get_datetime_expression( 'llm' );

		$seen_since = "EXISTS (
			SELECT 1 FROM {$wpdb->usermeta} llm
			WHERE llm.user_id = u.ID AND llm.meta_key = %s AND llm.meta_value != ''
			  AND {$datetime_expression} >= %s
		)";

		$include_unknown = isset( $args['include_unknown'] ) && (bool) $args['include_unknown'];

		if ( $include_unknown ) {
			return array( $seen_since, array( $source->meta_key, $cutoff ) );
		}

		$coverage = Last_Login::get_coverage();
		$earliest = null === $coverage ? '' : $coverage->earliest_record;

		if ( '' === $earliest ) {
			return array( '1=1', array() );
		}

		return array(
			"( {$seen_since} OR u.user_registered < %s )",
			array( $source->meta_key, $cutoff, $earliest ),
		);
	}
}

/**
 * Everything WordPress provides on its own.
 */
class Wp_Core_Integration extends Integration {

	/**
	 * Short identifier used to namespace filter IDs.
	 */
	public function get_id(): string {
		return 'wp-core';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'WordPress core', 'purge-user-accounts' );
	}

	/**
	 * Selection criteria supplied by this integration.
	 *
	 * @return Filter[]
	 */
	public function get_filters(): array {
		return array(
			new Wp_Core_Role_Filter(),
			new Wp_Core_Any_Role_Filter(),
			new Wp_Core_Content_Filter(),
			new Wp_Core_Comments_Filter(),
			new Wp_Core_Registered_Filter(),
			new Wp_Core_Bad_Signup_Filter(),
			new Wp_Core_Random_Identity_Filter(),
			new Wp_Core_Login_Record_Filter(),
			new Wp_Core_Active_Since_Filter(),
		);
	}

	/**
	 * Bulk actions supplied by WP Core.
	 *
	 * Ordered by the registry, least destructive first, so the safe option is
	 * the one nearest to hand.
	 *
	 * @return Action[]
	 */
	public function get_actions(): array {
		return array(
			new Force_Logout_Action(),
			new Restore_Roles_Action(),
			new Revoke_App_Passwords_Action(),
			new Unblock_Signin_Action(),
			new Strip_Roles_Action(),
			new Block_Signin_Action(),
			new Scramble_Password_Action(),
			new Delete_Users_Action(),
		);
	}

	/**
	 * This plugin's own login recorder.
	 *
	 * The only source whose semantics are exactly right and whose start date we
	 * know with certainty. Worthless on day one, authoritative after a year -
	 * which is why recording begins at activation and is not optional.
	 *
	 * @return Last_Login_Source|null
	 */
	public function get_last_login_source(): ?Last_Login_Source {
		$settings     = new Settings();
		$activated_at = $settings->get_string( OPT_ACTIVATED_AT );

		return new Last_Login_Source(
			'hwpua-native',
			__( 'Purge User Accounts (this plugin)', 'purge-user-accounts' ),
			10,
			META_LAST_LOGIN,
			Last_Login_Source::FORMAT_DATETIME,
			Last_Login_Source::SEMANTICS_LOGIN,
			$activated_at
		);
	}
}

add_filter(
	'hwpua_register_integrations',
	static function ( array $integrations ): array {
		$integrations[] = new Wp_Core_Integration();

		return $integrations;
	}
);
