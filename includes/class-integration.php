<?php
/**
 * Base class for integrations.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * A system that owns user-attached data, and what it contributes.
 *
 * Criteria are grouped by the system that owns the data - WP Core, WooCommerce,
 * LearnDash - because that is the axis availability runs along. WooCommerce
 * being absent is one detection call and one explanation, not three disabled
 * checkboxes each repeating the same reason.
 *
 * See dev-notes/12-integration-framework.md.
 */
abstract class Integration {

	/**
	 * Short identifier used to namespace filter IDs, e.g. 'woocommerce'.
	 */
	abstract public function get_id(): string;

	/**
	 * Human-readable name for the UI.
	 */
	abstract public function get_label(): string;

	/**
	 * Whether the underlying system is present on this installation.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Why the integration is unavailable, stated rather than hidden.
	 */
	public function get_unavailable_reason(): string {
		return '';
	}

	/**
	 * Selection criteria this integration supplies.
	 *
	 * @return Filter[]
	 */
	public function get_filters(): array {
		return array();
	}

	/**
	 * Bulk actions this integration supplies.
	 *
	 * @return Action[]
	 */
	public function get_actions(): array {
		return array();
	}

	/**
	 * A last-login source this integration can supply, if any.
	 *
	 * A source is one of the things an integration contributes, alongside its
	 * filters - not a parallel registry. The WooCommerce integration supplies
	 * both purchase filters and `wc_last_active`; a security-plugin integration
	 * might supply only a source.
	 *
	 * @return Last_Login_Source|null
	 */
	public function get_last_login_source(): ?Last_Login_Source {
		return null;
	}
}
