<?php
/**
 * Users who must never be selected or acted upon.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * The narrow set of users an explicit query may not return.
 *
 * Guards are not judgements about evidence - that belongs in the query, as an
 * inverted filter. These protect against irreversible self-harm, and they are
 * the only thing that overrides what the operator asked for.
 *
 * Applied at selection so the count is honest, and re-applied per chunk at
 * execution so a promotion between the two is caught.
 */
class Guards {

	const REASON_SELF       = 'self';
	const REASON_PRIVILEGED = 'privileged';
	const REASON_LAST_ADMIN = 'last_administrator';

	/**
	 * Role slugs that can delete users, resolved once per request.
	 *
	 * @var string[]|null
	 */
	protected static ?array $privileged_roles = null;

	/**
	 * User IDs excluded from every run and every job.
	 *
	 * @return int[]
	 */
	public static function get_excluded_user_ids(): array {
		$excluded_ids = array();

		$current_user_id = get_current_user_id();
		if ( $current_user_id > 0 ) {
			$excluded_ids[] = $current_user_id;
		}

		/**
		 * Filters the guarded user IDs.
		 *
		 * This can only ever ADD protected users. The current user is re-added
		 * below, because a filter that could unguard them would be a
		 * privilege-escalation vector.
		 *
		 * @param int[] $excluded_ids Guarded user IDs.
		 */
		$filtered_ids = apply_filters( 'hwpua_guarded_user_ids', $excluded_ids );
		$filtered_ids = is_array( $filtered_ids ) ? array_map( 'absint', $filtered_ids ) : array();

		if ( $current_user_id > 0 ) {
			$filtered_ids[] = $current_user_id;
		}

		return array_values( array_unique( array_filter( $filtered_ids ) ) );
	}

	/**
	 * Roles that hold the capability this plugin is gated on.
	 *
	 * Resolved from the role definitions rather than by asking each user, which
	 * would hydrate a WP_User per row.
	 *
	 * @return string[]
	 */
	public static function get_privileged_roles(): array {
		if ( null === self::$privileged_roles ) {
			$privileged = array();

			foreach ( wp_roles()->get_names() as $role_slug => $role_label ) {
				unset( $role_label );
				$role_object = get_role( $role_slug );

				if ( $role_object instanceof \WP_Role && $role_object->has_cap( REQUIRED_CAPABILITY ) ) {
					$privileged[] = $role_slug;
				}
			}

			self::$privileged_roles = $privileged;
		}

		return self::$privileged_roles;
	}

	/**
	 * How many administrators the site has.
	 *
	 * @return int
	 */
	public static function count_administrators(): int {
		global $wpdb;

		// Counted directly rather than through count_users(), which WordPress
		// caches. A stale count here would let the last administrator through -
		// the single outcome this guard exists to prevent.
		$capabilities_key = Environment::get_capabilities_meta_key();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$capabilities_key,
				'%' . $wpdb->esc_like( '"administrator"' ) . '%'
			)
		);
	}

	/**
	 * Decide which users in a chunk must be skipped, and why.
	 *
	 * Re-applied on every chunk rather than trusted from selection time: a run
	 * built on Tuesday and executed on Thursday may contain someone promoted
	 * since. One query per chunk, not one per user.
	 *
	 * @param int[] $user_ids         Candidate users.
	 * @param bool  $allow_privileged Whether the operator opted in to acting on
	 *                                users who can delete users.
	 * @return array<int,string> User ID => guard reason.
	 */
	public static function find_protected( array $user_ids, bool $allow_privileged = false ): array {
		global $wpdb;

		$protected = array();

		if ( empty( $user_ids ) ) {
			return $protected;
		}

		$current_user_id = get_current_user_id();

		foreach ( self::get_excluded_user_ids() as $excluded_id ) {
			if ( in_array( $excluded_id, $user_ids, true ) ) {
				$protected[ $excluded_id ] = $excluded_id === $current_user_id
					? self::REASON_SELF
					: self::REASON_PRIVILEGED;
			}
		}

		$privileged_roles = self::get_privileged_roles();

		if ( empty( $privileged_roles ) ) {
			return $protected;
		}

		$placeholders     = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$capabilities_key = Environment::get_capabilities_meta_key();
		$params           = array_merge( array( $capabilities_key ), $user_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders counted from the array; every value is bound.
		$caps_sql = "SELECT user_id, meta_value FROM {$wpdb->usermeta}
			WHERE meta_key = %s AND user_id IN ({$placeholders})";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $wpdb->prepare( $caps_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here with bound values.

		if ( ! is_array( $rows ) ) {
			// Fail closed: if we cannot establish who is privileged, protect
			// everyone rather than risk deleting an administrator.
			hwpua_log_error( 'Guard check could not read capabilities; the whole chunk was protected.' );

			foreach ( $user_ids as $user_id ) {
				$protected[ $user_id ] = self::REASON_PRIVILEGED;
			}

			return $protected;
		}

		$administrators_in_chunk = 0;

		foreach ( $rows as $row ) {
			$user_id      = (int) $row->user_id;
			$capabilities = maybe_unserialize( $row->meta_value );
			$roles        = is_array( $capabilities ) ? array_keys( array_filter( $capabilities ) ) : array();

			if ( in_array( 'administrator', $roles, true ) ) {
				++$administrators_in_chunk;
			}

			if ( ! $allow_privileged && array_intersect( $roles, $privileged_roles )
				&& ! isset( $protected[ $user_id ] ) ) {
				$protected[ $user_id ] = self::REASON_PRIVILEGED;
			}
		}

		// Never the last administrator, whatever the operator opted into.
		if ( $administrators_in_chunk > 0 && $administrators_in_chunk >= self::count_administrators() ) {
			foreach ( $rows as $row ) {
				$capabilities = maybe_unserialize( $row->meta_value );
				$roles        = is_array( $capabilities ) ? array_keys( array_filter( $capabilities ) ) : array();

				if ( in_array( 'administrator', $roles, true ) ) {
					$protected[ (int) $row->user_id ] = self::REASON_LAST_ADMIN;
				}
			}
		}

		return $protected;
	}

	/**
	 * Operator-facing wording for a guard reason.
	 *
	 * @param string $reason One of the REASON_* constants.
	 * @return string
	 */
	public static function describe_reason( string $reason ): string {
		$wording = array(
			self::REASON_SELF       => __( 'This is your own account.', 'purge-user-accounts' ),
			self::REASON_PRIVILEGED => __( 'This user can manage other users.', 'purge-user-accounts' ),
			self::REASON_LAST_ADMIN => __( 'This is the last administrator on the site.', 'purge-user-accounts' ),
		);

		return $wording[ $reason ] ?? __( 'Protected.', 'purge-user-accounts' );
	}

	/**
	 * Reset the cached role list. Test support.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$privileged_roles = null;
	}
}
