<?php
/**
 * Runtime environment detection and support gating.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Answers what this installation can support, before any hook is registered.
 *
 * Every answer here is derived at call time and cached for the request only.
 * Nothing is persisted, because a plugin can be activated or deactivated
 * between one request and the next.
 */
class Environment {

	/**
	 * Per-request cache of derived answers.
	 *
	 * @var array<string,mixed>
	 */
	protected static array $cache = array();

	/**
	 * Whether the plugin can run at all on this installation.
	 */
	public static function is_supported(): bool {
		return '' === self::get_unsupported_reason();
	}

	/**
	 * Why the plugin is inert, or an empty string when it is supported.
	 */
	public static function get_unsupported_reason(): string {
		$reason = '';

		if ( is_multisite() ) {
			$reason = __( 'Purge User Accounts does not support multisite networks. Role capabilities are stored per site and network user deletion has different semantics, so running here could remove users from sites you did not intend.', 'purge-user-accounts' );
		}

		return $reason;
	}

	/**
	 * Whether WooCommerce is active in this request.
	 */
	public static function has_woocommerce(): bool {
		if ( ! array_key_exists( 'has_woocommerce', self::$cache ) ) {
			self::$cache['has_woocommerce'] = class_exists( 'WooCommerce' );
		}

		return self::$cache['has_woocommerce'];
	}

	/**
	 * Whether WooCommerce is storing orders in HPOS rather than the legacy post table.
	 *
	 * Never assume either store. Across the Headwall fleet the split is roughly
	 * 51 legacy to 21 HPOS, and the two need different SQL entirely.
	 */
	public static function uses_hpos(): bool {
		if ( ! array_key_exists( 'uses_hpos', self::$cache ) ) {
			$uses_hpos = false;

			if ( self::has_woocommerce() && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
				$uses_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			}

			self::$cache['uses_hpos'] = $uses_hpos;
		}

		return self::$cache['uses_hpos'];
	}

	/**
	 * Whether LearnDash is active in this request.
	 */
	public static function has_learndash(): bool {
		if ( ! array_key_exists( 'has_learndash', self::$cache ) ) {
			self::$cache['has_learndash'] = defined( 'LEARNDASH_VERSION' );
		}

		return self::$cache['has_learndash'];
	}

	/**
	 * Whether the current user may operate this plugin.
	 */
	public static function current_user_can_operate(): bool {
		return current_user_can( REQUIRED_CAPABILITY );
	}

	/**
	 * The meta key holding role capabilities on this install.
	 *
	 * Prefix-dependent. Hardcoding `wp_capabilities` breaks on any site with a
	 * non-default table prefix, which is most of them.
	 */
	public static function get_capabilities_meta_key(): string {
		global $wpdb;

		return $wpdb->prefix . 'capabilities';
	}

	/**
	 * Reset the per-request cache. Test support only.
	 */
	public static function flush_cache(): void {
		self::$cache = array();
	}
}
