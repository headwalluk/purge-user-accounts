<?php
/**
 * WooCommerce integration.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Has one or more qualifying WooCommerce orders.
 *
 * Used with sense `has_not` for "has never purchased". Reaches 85% of at-risk
 * accounts across the Headwall fleet, so this is not an optional integration.
 *
 * Two storage backends, and the site may be running either. The fleet splits
 * roughly 51 legacy to 21 HPOS, so both paths are load-bearing - never assume.
 */
class Woocommerce_Orders_Filter extends Filter {

	/**
	 * Scratch bucket name, used by both storage backends.
	 */
	public function get_scratch_bucket(): string {
		return 'wc_customer';
	}

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'woocommerce.orders';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'Has WooCommerce orders', 'purge-user-accounts' );
	}

	/**
	 * UI grouping key.
	 */
	public function get_group(): string {
		return 'purchases';
	}

	/**
	 * Needs WooCommerce.
	 */
	public function is_available(): bool {
		return Environment::has_woocommerce();
	}

	/**
	 * Why the criterion cannot be used here.
	 */
	public function get_unavailable_reason(): string {
		return __( 'WooCommerce is not active on this site.', 'purge-user-accounts' );
	}

	/**
	 * Statuses that count as a purchase unless the operator says otherwise.
	 *
	 * Deliberately more generous than WooCommerce's own paid-status list, which
	 * is just processing and completed. A refunded order still means the person
	 * bought something, and an on-hold one usually means payment is clearing.
	 *
	 * Sparing a junk account costs nothing. Deleting a real customer costs a
	 * complaint and an orphaned order.
	 *
	 * @return string[]
	 */
	public static function get_default_statuses(): array {
		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );

		return array_values( array_unique( array_merge( $paid_statuses, array( 'on-hold', 'refunded' ) ) ) );
	}

	/**
	 * Normalise operator-supplied statuses to the `wc-` prefixed form.
	 *
	 * A site may register arbitrary custom statuses - this one carries
	 * `wc-internal-review` and `wc-expired-quote` - so the UI enumerates what is
	 * actually registered rather than offering a hardcoded list.
	 *
	 * @param array<string,mixed> $args Operator arguments.
	 * @return string[]
	 */
	protected function resolve_statuses( array $args ): array {
		$statuses = isset( $args['statuses'] ) && is_array( $args['statuses'] )
			? $args['statuses']
			: self::get_default_statuses();

		$normalised = array();

		foreach ( $statuses as $status ) {
			$slug = sanitize_key( (string) $status );

			if ( '' !== $slug ) {
				$normalised[] = str_starts_with( $slug, 'wc-' ) ? $slug : 'wc-' . $slug;
			}
		}

		return array_values( array_unique( $normalised ) );
	}

	/**
	 * Materialise every user who has bought something.
	 *
	 * A scratch bucket rather than a correlated subquery, on both backends: the
	 * seed is chunked, and a correlated form is re-materialised per chunk -
	 * measured at 30x slower across 41 chunks. See
	 * dev-notes/reference/benchmarks-m3-2026-09-12.md §3.
	 *
	 * @param int                 $run_id Run being built.
	 * @param array<string,mixed> $args   Operator-supplied arguments.
	 * @return array<int,array{0:string,1:array<int,mixed>}>
	 */
	public function get_scratch_queries( int $run_id, array $args ): array {
		$statuses = $this->resolve_statuses( $args );
		$queries  = array();

		$queries[] = Environment::uses_hpos()
			? $this->build_hpos_scratch( $run_id, $statuses )
			: $this->build_legacy_scratch( $run_id, $statuses );

		// Default on: a customer who ordered as a guest before registering is
		// linked to that order only by their address.
		$check_guest_orders = ! isset( $args['include_guest_email'] ) || (bool) $args['include_guest_email'];

		if ( $check_guest_orders ) {
			$queries[] = $this->build_guest_email_scratch( $run_id, $statuses );
		}

		return $queries;
	}

	/**
	 * Scratch query against the HPOS orders table.
	 *
	 * `wp_wc_orders` carries KEY customer_id_status and KEY billing_email, so
	 * both halves are properly indexed.
	 *
	 * @param int      $run_id   Run being built.
	 * @param string[] $statuses Statuses counting as a purchase.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	protected function build_hpos_scratch( int $run_id, array $statuses ): array {
		global $wpdb;

		$scratch_table = Schema::table( TABLE_RUN_SCRATCH );
		$orders_table  = $wpdb->prefix . 'wc_orders';
		$status_slugs  = $this->resolve_statuses( array( 'statuses' => $statuses ) );
		$placeholders  = implode( ',', array_fill( 0, count( $status_slugs ), '%s' ) );

		// Customers by ID, plus guest orders matched on the billing address.
		$scratch_sql = "INSERT IGNORE INTO {$scratch_table} (run_id, bucket, user_id)
			SELECT %d, 'wc_customer', o.customer_id
			FROM {$orders_table} o
			WHERE o.customer_id > 0 AND o.type = 'shop_order' AND o.status IN ({$placeholders})
			GROUP BY o.customer_id";

		$params = array_merge( array( $run_id ), $status_slugs );

		return array( $scratch_sql, $params );
	}

	/**
	 * Scratch query against the legacy CPT order store.
	 *
	 * `_customer_user` lives in postmeta, whose only useful index is on
	 * meta_key, so this is materialised once rather than probed per user.
	 *
	 * @param int      $run_id   Run being built.
	 * @param string[] $statuses Statuses counting as a purchase.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	protected function build_legacy_scratch( int $run_id, array $statuses ): array {
		global $wpdb;

		$scratch_table = Schema::table( TABLE_RUN_SCRATCH );
		$status_slugs  = $this->resolve_statuses( array( 'statuses' => $statuses ) );
		$placeholders  = implode( ',', array_fill( 0, count( $status_slugs ), '%s' ) );

		$scratch_sql = "INSERT IGNORE INTO {$scratch_table} (run_id, bucket, user_id)
			SELECT %d, 'wc_customer', CAST(pm.meta_value AS UNSIGNED)
			FROM {$wpdb->postmeta} pm
			JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_customer_user'
			  AND pm.meta_value > 0
			  AND p.post_type = 'shop_order'
			  AND p.post_status IN ({$placeholders})
			GROUP BY pm.meta_value";

		$params = array_merge( array( $run_id ), $status_slugs );

		return array( $scratch_sql, $params );
	}

	/**
	 * Add users whose email appears on a guest order.
	 *
	 * A customer may have ordered as a guest BEFORE registering, leaving
	 * customer_id at 0 and only their address on the order. Matching on
	 * customer_id alone marks them as never-purchased and deletes them along
	 * with the link to their order history.
	 *
	 * Default on. Turning it off is a deliberate act.
	 *
	 * @param int      $run_id   Run being built.
	 * @param string[] $statuses Statuses counting as a purchase.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	protected function build_guest_email_scratch( int $run_id, array $statuses ): array {
		global $wpdb;

		$scratch_table = Schema::table( TABLE_RUN_SCRATCH );
		$status_slugs  = $this->resolve_statuses( array( 'statuses' => $statuses ) );
		$placeholders  = implode( ',', array_fill( 0, count( $status_slugs ), '%s' ) );

		if ( Environment::uses_hpos() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$guest_sql    = "INSERT IGNORE INTO {$scratch_table} (run_id, bucket, user_id)
				SELECT %d, 'wc_customer', u.ID
				FROM {$wpdb->users} u
				JOIN {$orders_table} o ON o.billing_email = u.user_email
				WHERE o.type = 'shop_order' AND o.status IN ({$placeholders})
				GROUP BY u.ID";
		} else {
			$guest_sql = "INSERT IGNORE INTO {$scratch_table} (run_id, bucket, user_id)
				SELECT %d, 'wc_customer', u.ID
				FROM {$wpdb->users} u
				JOIN {$wpdb->postmeta} bm ON bm.meta_key = '_billing_email' AND bm.meta_value = u.user_email
				JOIN {$wpdb->posts} p ON p.ID = bm.post_id
				WHERE p.post_type = 'shop_order' AND p.post_status IN ({$placeholders})
				GROUP BY u.ID";
		}

		return array( $guest_sql, array_merge( array( $run_id ), $status_slugs ) );
	}

	/**
	 * Matches when the user appears in the materialised customer set.
	 *
	 * @param array<string,mixed> $args   Unused here; statuses are applied when
	 *                                    the scratch bucket is built.
	 * @param int                 $run_id Run being built.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		unset( $args );

		$scratch_table = Schema::table( TABLE_RUN_SCRATCH );

		$predicate = "EXISTS (
			SELECT 1 FROM {$scratch_table} s
			WHERE s.run_id = %d AND s.bucket = 'wc_customer' AND s.user_id = u.ID
		)";

		return array( $predicate, array( $run_id ) );
	}
}

/**
 * WooCommerce.
 */
class Woocommerce_Integration extends Integration {

	/**
	 * Short identifier used to namespace filter IDs.
	 */
	public function get_id(): string {
		return 'woocommerce';
	}

	/**
	 * Human-readable name.
	 */
	public function get_label(): string {
		return __( 'WooCommerce', 'purge-user-accounts' );
	}

	/**
	 * Whether WooCommerce is active.
	 */
	public function is_available(): bool {
		return Environment::has_woocommerce();
	}

	/**
	 * Why the integration is unavailable.
	 */
	public function get_unavailable_reason(): string {
		return __( 'WooCommerce is not active on this site.', 'purge-user-accounts' );
	}

	/**
	 * Selection criteria supplied by this integration.
	 *
	 * @return Filter[]
	 */
	public function get_filters(): array {
		return array( new Woocommerce_Orders_Filter() );
	}

	/**
	 * WooCommerce's own activity timestamp.
	 *
	 * Semantics are ACTIVITY, not login: `wc_last_active` is written on
	 * `wp_login` and on every front-end page load by a signed-in user. Someone
	 * who stays signed in and browses weekly has a recent value and no logins at
	 * all.
	 *
	 * Safe as an exclusion signal, unsafe as an inclusion one - which is why the
	 * UI must say "last active" when this is the source, never "last login".
	 *
	 * @return Last_Login_Source|null
	 */
	public function get_last_login_source(): ?Last_Login_Source {
		$source = null;

		if ( Environment::has_woocommerce() ) {
			$source = new Last_Login_Source(
				'woocommerce-last-active',
				__( 'WooCommerce last active', 'purge-user-accounts' ),
				20,
				'wc_last_active',
				Last_Login_Source::FORMAT_UNIX,
				Last_Login_Source::SEMANTICS_ACTIVITY
			);
		}

		return $source;
	}
}

add_filter(
	'hwpua_register_integrations',
	static function ( array $integrations ): array {
		$integrations[] = new Woocommerce_Integration();

		return $integrations;
	}
);
