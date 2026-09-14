<?php
/**
 * Type-safe access to plugin options.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Reads and writes plugin options with a declared type.
 *
 * Every option this plugin owns is reached through here, so a malformed stored
 * value becomes a predictable default rather than a type error somewhere else.
 */
class Settings {

	/**
	 * Read an option as a string.
	 *
	 * @param string $option_name   Option key.
	 * @param string $default_value Returned when absent or malformed.
	 * @return string
	 */
	public function get_string( string $option_name, string $default_value = '' ): string {
		$stored_value = get_option( $option_name, $default_value );

		return is_scalar( $stored_value ) ? (string) $stored_value : $default_value;
	}

	/**
	 * Read an option as an integer, clamped to an optional range.
	 *
	 * @param string   $option_name   Option key.
	 * @param int      $default_value Returned when absent or malformed.
	 * @param int|null $minimum       Lower bound, or null for none.
	 * @param int|null $maximum       Upper bound, or null for none.
	 * @return int
	 */
	public function get_int( string $option_name, int $default_value = 0, ?int $minimum = null, ?int $maximum = null ): int {
		$stored_value = get_option( $option_name, $default_value );
		$result_value = is_numeric( $stored_value ) ? (int) $stored_value : $default_value;

		if ( null !== $minimum ) {
			$result_value = max( $minimum, $result_value );
		}

		if ( null !== $maximum ) {
			$result_value = min( $maximum, $result_value );
		}

		return $result_value;
	}

	/**
	 * Read an option as a boolean.
	 *
	 * @param string $option_name   Option key.
	 * @param bool   $default_value Returned when absent.
	 * @return bool
	 */
	public function get_bool( string $option_name, bool $default_value = false ): bool {
		$stored_value = get_option( $option_name, $default_value );

		return filter_var( $stored_value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Read an option as an array.
	 *
	 * @param string       $option_name   Option key.
	 * @param array<mixed> $default_value Returned when absent or malformed.
	 * @return array<mixed>
	 */
	public function get_array( string $option_name, array $default_value = array() ): array {
		$stored_value = get_option( $option_name, $default_value );

		return is_array( $stored_value ) ? $stored_value : $default_value;
	}

	/**
	 * Write a string option.
	 *
	 * @param string $option_name Option key.
	 * @param string $new_value   Value to store.
	 * @param bool   $autoload    Whether WordPress should autoload it.
	 * @return void
	 */
	public function set_string( string $option_name, string $new_value, bool $autoload = false ): void {
		update_option( $option_name, $new_value, $autoload );
	}

	/**
	 * Write an integer option.
	 *
	 * @param string $option_name Option key.
	 * @param int    $new_value   Value to store.
	 * @param bool   $autoload    Whether WordPress should autoload it.
	 * @return void
	 */
	public function set_int( string $option_name, int $new_value, bool $autoload = false ): void {
		update_option( $option_name, $new_value, $autoload );
	}

	/**
	 * Write a boolean option, stored as '1' or '0'.
	 *
	 * @param string $option_name Option key.
	 * @param bool   $new_value   Value to store.
	 * @param bool   $autoload    Whether WordPress should autoload it.
	 * @return void
	 */
	public function set_bool( string $option_name, bool $new_value, bool $autoload = false ): void {
		update_option( $option_name, $new_value ? '1' : '0', $autoload );
	}

	/**
	 * Write an array option.
	 *
	 * @param string       $option_name Option key.
	 * @param array<mixed> $new_value   Value to store.
	 * @param bool         $autoload    Whether WordPress should autoload it.
	 * @return void
	 */
	public function set_array( string $option_name, array $new_value, bool $autoload = false ): void {
		update_option( $option_name, $new_value, $autoload );
	}
}
