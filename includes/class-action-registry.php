<?php
/**
 * Action discovery across integrations.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Collects the actions every available integration supplies.
 */
class Action_Registry {

	/**
	 * Actions keyed by id, ordered least destructive first.
	 *
	 * @var array<string,Action>|null
	 */
	protected static ?array $actions = null;

	/**
	 * Every action from an available integration.
	 *
	 * @return array<string,Action>
	 */
	public static function get_actions(): array {
		if ( null === self::$actions ) {
			$collected = array();

			foreach ( Integration_Registry::get_integrations() as $integration ) {
				if ( ! $integration->is_available() ) {
					continue;
				}

				foreach ( $integration->get_actions() as $action ) {
					if ( $action instanceof Action ) {
						$collected[ $action->get_id() ] = $action;
					}
				}
			}

			// Least destructive first: the UI presents this as a ladder, and the
			// safe option should be the one nearest to hand.
			uasort(
				$collected,
				static fn( Action $first, Action $second ): int => $first->get_destructiveness() <=> $second->get_destructiveness()
			);

			self::$actions = $collected;
		}

		return self::$actions;
	}

	/**
	 * One action by id, or null.
	 *
	 * @param string $action_id Action identifier.
	 * @return Action|null
	 */
	public static function get_action( string $action_id ): ?Action {
		$actions = self::get_actions();

		return $actions[ $action_id ] ?? null;
	}

	/**
	 * Reset the cache. Test support.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$actions = null;
	}
}
