<?php
/**
 * Plugin-scope helper functions.
 *
 * @package PurgeUserAccounts
 */

defined( 'ABSPATH' ) || die();

/**
 * Record a plugin error somewhere durable.
 *
 * Every caught failure in this plugin either rethrows or lands here. A caught
 * error that leaves no trace is what produces clean logs and broken behaviour,
 * which for a tool that deletes people is the worst available outcome.
 *
 * @param string $message Operator-safe description of what failed.
 */
function hwpua_log_error( string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'PurgeUserAccounts: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate error channel; see docblock.
	}

	/**
	 * Fires when the plugin records an error.
	 *
	 * Integrations and host tooling can route these somewhere durable.
	 *
	 * @param string $message Operator-safe description of what failed.
	 */
	do_action( 'hwpua_error', $message );
}

/**
 * Current UTC time in MySQL datetime format.
 */
function hwpua_now(): string {
	return gmdate( 'Y-m-d H:i:s' );
}
