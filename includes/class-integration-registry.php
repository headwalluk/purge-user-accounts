<?php
/**
 * Integration discovery, and the filters they supply.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Collects integrations and flattens their filters.
 *
 * `hwpua_register_integrations` is the single extension point. There are
 * deliberately no separate hooks for registering a filter or an action on its
 * own - an integration is the unit of extension.
 */
class Integration_Registry {

	/**
	 * Registered integrations, keyed by id.
	 *
	 * @var array<string,Integration>|null
	 */
	protected static ?array $integrations = null;

	/**
	 * Filters across every available integration, keyed by namespaced id.
	 *
	 * @var array<string,Filter>|null
	 */
	protected static ?array $filters = null;

	/**
	 * Every registered integration, available or not.
	 *
	 * @return array<string,Integration>
	 */
	public static function get_integrations(): array {
		if ( null === self::$integrations ) {
			$collected = array();

			/**
			 * Filters the registered integrations.
			 *
			 * @param Integration[] $collected Integration instances.
			 */
			$candidates = apply_filters( 'hwpua_register_integrations', array() );
			$candidates = is_array( $candidates ) ? $candidates : array();

			foreach ( $candidates as $candidate ) {
				if ( $candidate instanceof Integration ) {
					$collected[ $candidate->get_id() ] = $candidate;
				} else {
					hwpua_log_error( 'An object registered on hwpua_register_integrations is not an Integration and was ignored.' );
				}
			}

			self::$integrations = $collected;
		}

		return self::$integrations;
	}

	/**
	 * One integration by id, or null.
	 *
	 * @param string $integration_id Integration identifier.
	 * @return Integration|null
	 */
	public static function get_integration( string $integration_id ): ?Integration {
		$integrations = self::get_integrations();

		return $integrations[ $integration_id ] ?? null;
	}

	/**
	 * Every filter supplied by an available integration.
	 *
	 * @return array<string,Filter>
	 */
	public static function get_filters(): array {
		if ( null === self::$filters ) {
			$collected = array();

			foreach ( self::get_integrations() as $integration ) {
				if ( ! $integration->is_available() ) {
					continue;
				}

				foreach ( $integration->get_filters() as $filter ) {
					if ( self::declares_per_user_callback( $filter ) ) {
						hwpua_log_error(
							sprintf(
								'Filter "%s" declares a per-user callback, which is not supported. Per-user iteration hydrates rows at roughly 52 MB per 100,000 users and issues one query per user per filter. Use evaluate_chunk(). The filter was refused.',
								$filter->get_id()
							)
						);
						continue;
					}

					$collected[ $filter->get_id() ] = $filter;
				}
			}

			self::$filters = $collected;
		}

		return self::$filters;
	}

	/**
	 * One filter by namespaced id, or null.
	 *
	 * @param string $filter_id Namespaced filter identifier.
	 * @return Filter|null
	 */
	public static function get_filter( string $filter_id ): ?Filter {
		$filters = self::get_filters();

		return $filters[ $filter_id ] ?? null;
	}

	/**
	 * Whether a filter has tried to add a per-user evaluation method.
	 *
	 * The constraint is load-bearing, so it is enforced here rather than left to
	 * review. See dev-notes/12-integration-framework.md §3.
	 *
	 * @param Filter $filter Filter to inspect.
	 * @return bool
	 */
	protected static function declares_per_user_callback( Filter $filter ): bool {
		$forbidden_methods = array( 'evaluate_user', 'evaluate_single', 'score_user' );
		$has_forbidden     = false;

		foreach ( $forbidden_methods as $method_name ) {
			if ( method_exists( $filter, $method_name ) ) {
				$has_forbidden = true;
				break;
			}
		}

		return $has_forbidden;
	}

	/**
	 * Reset the caches. Test support, and after activating another plugin.
	 */
	public static function flush_cache(): void {
		self::$integrations = null;
		self::$filters      = null;
	}
}
