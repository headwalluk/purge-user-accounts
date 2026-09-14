<?php
/**
 * Refusing authentication for blocked accounts.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Enforces the sign-in block.
 *
 * Removing an account's roles is not the same as locking it out. A role-less
 * user still AUTHENTICATES, and plenty of plugin code gates on
 * `is_user_logged_in() && wp_verify_nonce()` without ever asking
 * `current_user_can()`. That is a recurring vulnerability class, and a stripped
 * account still walks through every door of that shape.
 *
 * So this blocks authentication itself, at three points:
 *
 *  - `authenticate`             - username, email and application-password logins
 *  - `determine_current_user`   - existing cookies, and anything that sets an
 *                                 auth cookie directly, which is how most social
 *                                 login plugins work
 *  - `allow_password_reset`     - the reset route, which matters because most
 *                                 disposable mail providers are PUBLIC inboxes
 *
 * The block is a single meta flag and is instantly reversible.
 */
class Signin_Block {

	/**
	 * Register the enforcement hooks.
	 *
	 * @return void
	 */
	public function run(): void {
		// After core's own authenticators, so we are rejecting a resolved user.
		add_filter( 'authenticate', array( $this, 'refuse_authentication' ), 100, 1 );
		add_filter( 'determine_current_user', array( $this, 'refuse_current_user' ), 100, 1 );
		add_filter( 'allow_password_reset', array( $this, 'refuse_password_reset' ), 100, 2 );
	}

	/**
	 * Whether an account is blocked.
	 *
	 * @param int $user_id User to check.
	 * @return bool
	 */
	public static function is_blocked( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, META_SIGNIN_BLOCKED, true );
	}

	/**
	 * Block an account and end everything it currently holds.
	 *
	 * @param int $user_id User to block.
	 * @param int $job_id  Job responsible, for the audit trail.
	 * @return void
	 */
	public static function block( int $user_id, int $job_id = 0 ): void {
		update_user_meta( $user_id, META_SIGNIN_BLOCKED, $job_id > 0 ? (string) $job_id : '1' );
		update_user_meta( $user_id, META_SIGNIN_BLOCKED_AT, hwpua_now() );

		// A block that leaves a live session running has not blocked anything.
		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		if ( class_exists( '\WP_Application_Passwords' ) ) {
			\WP_Application_Passwords::delete_all_application_passwords( $user_id );
		}
	}

	/**
	 * Lift a block.
	 *
	 * @param int $user_id User to unblock.
	 * @return bool Whether the account was blocked to begin with.
	 */
	public static function unblock( int $user_id ): bool {
		$was_blocked = self::is_blocked( $user_id );

		delete_user_meta( $user_id, META_SIGNIN_BLOCKED );
		delete_user_meta( $user_id, META_SIGNIN_BLOCKED_AT );

		return $was_blocked;
	}

	/**
	 * Refuse a login attempt for a blocked account.
	 *
	 * @param \WP_User|\WP_Error|null $user Result so far.
	 * @return \WP_User|\WP_Error|null
	 */
	public function refuse_authentication( $user ) {
		if ( $user instanceof \WP_User && self::is_blocked( $user->ID ) ) {
			return new \WP_Error(
				'hwpua_signin_blocked',
				__( '<strong>Error:</strong> This account has been disabled by an administrator.', 'purge-user-accounts' )
			);
		}

		return $user;
	}

	/**
	 * Refuse to resolve a blocked account as the current user.
	 *
	 * This is what catches an existing auth cookie, and anything that sets one
	 * directly rather than going through `authenticate` - which is how most
	 * social login plugins sign a user in.
	 *
	 * @param int|false $user_id Resolved user ID, or false.
	 * @return int|false
	 */
	public function refuse_current_user( $user_id ) {
		if ( is_numeric( $user_id ) && (int) $user_id > 0 && self::is_blocked( (int) $user_id ) ) {
			return false;
		}

		return $user_id;
	}

	/**
	 * Refuse a password reset for a blocked account.
	 *
	 * Load-bearing rather than tidy: most of the disposable mail providers this
	 * plugin's rules look for - mailinator, yopmail, guerrillamail, sharklasers -
	 * are PUBLIC inboxes. Without this, whoever controls that mailbox simply
	 * resets the password and signs back in.
	 *
	 * @param bool $is_allowed Whether a reset is permitted.
	 * @param int  $user_id    User requesting it.
	 * @return bool
	 */
	public function refuse_password_reset( $is_allowed, $user_id ) {
		return self::is_blocked( (int) $user_id ) ? false : $is_allowed;
	}
}
