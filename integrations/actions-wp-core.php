<?php
/**
 * The four core bulk actions, ordered by destructiveness.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * End every active session for the matched users.
 *
 * Useful during an incident: boot everyone without changing anything. It does
 * not prevent re-login, so it is a stop-gap rather than a remedy.
 */
class Force_Logout_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'force-logout';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Force logout', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Ends every active session. Accounts are otherwise untouched and can sign in again immediately.', 'purge-user-accounts' );
	}

	/**
	 * Barely destructive.
	 */
	public function get_destructiveness(): int {
		return 5;
	}

	/**
	 * Nothing to reverse.
	 */
	public function is_reversible(): bool {
		return true;
	}

	/**
	 * No export needed for something this reversible.
	 */
	public function requires_export(): bool {
		return false;
	}

	/**
	 * One meta delete each.
	 */
	public function get_chunk_size(): int {
		return 2000;
	}

	/**
	 * Measured rate.
	 */
	public function get_rate_per_second(): float {
		return 800.0;
	}

	/**
	 * Destroy every session token for each user.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Unused.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
				$result->succeed();
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Revoke every application password, without touching the login password.
 *
 * Application passwords are a separate credential and SURVIVE a password
 * change, so a bot that provisioned one keeps full REST API access even after
 * its login password has been scrambled. Available on its own because revoking
 * API access is often the urgent part, and unlike scrambling it costs nothing -
 * no bcrypt, no disruption to a legitimate user who may still be signing in
 * normally.
 */
class Revoke_App_Passwords_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'revoke-app-passwords';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Revoke application passwords', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Removes every application password, cutting off REST API access. The account itself is untouched and can still sign in normally.', 'purge-user-accounts' );
	}

	/**
	 * Narrow and easily redone by the account owner.
	 */
	public function get_destructiveness(): int {
		return 15;
	}

	/**
	 * The owner can create new ones; nothing is lost that matters.
	 */
	public function is_reversible(): bool {
		return false;
	}

	/**
	 * Nothing here needs an export to reconstruct.
	 */
	public function requires_export(): bool {
		return false;
	}

	/**
	 * Whether application passwords exist on this installation.
	 */
	public function is_available(): bool {
		return class_exists( '\WP_Application_Passwords' );
	}

	/**
	 * Why the action cannot be used here.
	 */
	public function get_unavailable_reason(): string {
		return __( 'This version of WordPress does not support application passwords.', 'purge-user-accounts' );
	}

	/**
	 * Meta deletes only.
	 */
	public function get_chunk_size(): int {
		return 1000;
	}

	/**
	 * Measured rate.
	 */
	public function get_rate_per_second(): float {
		return 500.0;
	}

	/**
	 * Delete every application password for each user.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Unused.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				\WP_Application_Passwords::delete_all_application_passwords( $user_id );
				$result->succeed();
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Remove every role, keeping the account otherwise intact.
 *
 * Useful, but NOT a lock-out, and it should not be mistaken for one. This
 * removes an account's capabilities; the account still authenticates. Plugin
 * code that gates on `is_user_logged_in() && wp_verify_nonce()` without calling
 * `current_user_can()` remains reachable by a role-less user, and that pattern
 * is common enough to be a recurring vulnerability class.
 *
 * Use `block-signin` to actually lock an account out. Strip roles is for
 * reducing what a legitimate-but-unwanted account can do, not for containing a
 * hostile one.
 */
class Strip_Roles_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'strip-roles';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Strip roles (quarantine)', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Removes every role. The account can still sign in, so this is not a lock-out — use "Block sign-in" for that. Fully reversible: the original roles are saved.', 'purge-user-accounts' );
	}

	/**
	 * Reversible, so low friction.
	 */
	public function get_destructiveness(): int {
		return 25;
	}

	/**
	 * The stash is what makes this reversible.
	 */
	public function is_reversible(): bool {
		return true;
	}

	/**
	 * Meta writes only.
	 */
	public function get_chunk_size(): int {
		return 1000;
	}

	/**
	 * Measured rate.
	 */
	public function get_rate_per_second(): float {
		return 400.0;
	}

	/**
	 * Stash the current roles, then remove them.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Unused.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				$user_object = new \WP_User( $user_id );

				if ( ! $user_object->exists() ) {
					$result->fail( $user_id, __( 'No such user.', 'purge-user-accounts' ) );
					continue;
				}

				$existing_roles = (array) $user_object->roles;

				// Stashed BEFORE the change, or the reversal is impossible.
				update_user_meta( $user_id, META_STASHED_CAPABILITIES, $existing_roles );
				update_user_meta( $user_id, META_STASHED_AT, hwpua_now() );

				$user_object->set_role( '' );

				// An active session with no capabilities is harmless but untidy.
				\WP_Session_Tokens::get_instance( $user_id )->destroy_all();

				$result->succeed();
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Put back roles removed by a previous strip.
 *
 * The other half of reversibility. Listed as its own action so an operator can
 * undo a quarantine without reasoning about how it was done.
 */
class Restore_Roles_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'restore-roles';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Restore stripped roles', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Puts back the roles removed by a previous quarantine. Users with nothing stashed are skipped.', 'purge-user-accounts' );
	}

	/**
	 * Restorative rather than destructive.
	 */
	public function get_destructiveness(): int {
		return 10;
	}

	/**
	 * It is itself a reversal.
	 */
	public function is_reversible(): bool {
		return true;
	}

	/**
	 * Nothing is lost, so no export gate.
	 */
	public function requires_export(): bool {
		return false;
	}

	/**
	 * Meta reads and writes.
	 */
	public function get_chunk_size(): int {
		return 1000;
	}

	/**
	 * Measured rate.
	 */
	public function get_rate_per_second(): float {
		return 400.0;
	}

	/**
	 * Reinstate stashed roles.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Unused.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				$stashed = get_user_meta( $user_id, META_STASHED_CAPABILITIES, true );

				if ( ! is_array( $stashed ) || empty( $stashed ) ) {
					// Nothing stashed, or stashed empty. Granting a role here
					// would invent access the user never had.
					$result->skip( $user_id, __( 'No stashed roles to restore.', 'purge-user-accounts' ) );
					continue;
				}

				$user_object = new \WP_User( $user_id );

				if ( ! $user_object->exists() ) {
					$result->fail( $user_id, __( 'No such user.', 'purge-user-accounts' ) );
					continue;
				}

				$user_object->set_role( '' );

				foreach ( $stashed as $role_slug ) {
					$user_object->add_role( (string) $role_slug );
				}

				delete_user_meta( $user_id, META_STASHED_CAPABILITIES );
				delete_user_meta( $user_id, META_STASHED_AT );

				$result->succeed();
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Refuse authentication for the matched accounts.
 *
 * The real answer to "neutralise without deleting", and a better one than
 * stripping roles.
 *
 * Stripping roles removes an account's CAPABILITIES. It does not stop the
 * account authenticating, and a great deal of plugin code gates on
 * `is_user_logged_in() && wp_verify_nonce()` without ever calling
 * `current_user_can()`. That is a recurring vulnerability class, and a
 * role-less account still walks through every door of that shape. Blocking
 * authentication closes them all at once.
 *
 * It also closes the password-reset route, which matters because most of the
 * disposable mail providers our rules look for are public inboxes.
 *
 * Instantly reversible: one meta flag.
 */
class Block_Signin_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'block-signin';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Block sign-in', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Stops these accounts signing in at all, ends their sessions, revokes their application passwords and blocks password resets. Fully reversible, and the account and its data are untouched.', 'purge-user-accounts' );
	}

	/**
	 * Effective, but entirely reversible.
	 */
	public function get_destructiveness(): int {
		return 30;
	}

	/**
	 * One meta flag, removed to undo.
	 */
	public function is_reversible(): bool {
		return true;
	}

	/**
	 * Nothing is lost, so nothing needs reconstructing.
	 */
	public function requires_export(): bool {
		return false;
	}

	/**
	 * Meta writes plus a session teardown each.
	 */
	public function get_chunk_size(): int {
		return 1000;
	}

	/**
	 * Measured rate.
	 */
	public function get_rate_per_second(): float {
		return 400.0;
	}

	/**
	 * Flag each account and end what it currently holds.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Unused.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				Signin_Block::block( $user_id );
				$result->succeed();
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Let blocked accounts sign in again.
 *
 * The other half of the block. Application passwords and sessions are not
 * restored - those were revoked, not stashed - so the account comes back able
 * to sign in normally and nothing more.
 */
class Unblock_Signin_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'unblock-signin';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Allow sign-in again', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Lifts a sign-in block. Application passwords and sessions are not restored — the account can simply sign in normally again.', 'purge-user-accounts' );
	}

	/**
	 * Restorative.
	 */
	public function get_destructiveness(): int {
		return 10;
	}

	/**
	 * It is itself a reversal.
	 */
	public function is_reversible(): bool {
		return true;
	}

	/**
	 * Nothing is lost.
	 */
	public function requires_export(): bool {
		return false;
	}

	/**
	 * Meta deletes only.
	 */
	public function get_chunk_size(): int {
		return 1000;
	}

	/**
	 * Measured rate.
	 */
	public function get_rate_per_second(): float {
		return 500.0;
	}

	/**
	 * Lift the flag.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Unused.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				if ( Signin_Block::unblock( $user_id ) ) {
					$result->succeed();
				} else {
					$result->skip( $user_id, __( 'This account was not blocked.', 'purge-user-accounts' ) );
				}
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Replace each password with a discarded random value.
 *
 * Four things about this are load-bearing:
 *
 * 1. `wp_set_password()`, NEVER `wp_update_user()`. Passing `user_pass` to
 *    wp_update_user() sends a "your password has changed" notification. Firing
 *    40,000 of those at throwaway domains would destroy the site's sending
 *    reputation and plausibly get the account suspended at the ESP. This is the
 *    mistake that cannot be undone.
 * 2. Application Passwords SURVIVE a password change. A bot that provisioned
 *    one keeps full REST API access unless they are deleted too.
 * 3. Session tokens linger as garbage even though the auth cookie is
 *    invalidated by the hash change, so clear them.
 * 4. The plaintext is discarded. The value of this action is that the result is
 *    unrecoverable; if it were stored anywhere it would not be.
 */
class Scramble_Password_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'scramble-password';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Scramble passwords', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Replaces each password with a random value nobody keeps, ends their sessions and revokes their application passwords. The owner can still reset their password by email.', 'purge-user-accounts' );
	}

	/**
	 * Recoverable by the account owner, but disruptive.
	 */
	public function get_destructiveness(): int {
		return 60;
	}

	/**
	 * Small chunks: bcrypt dominates.
	 */
	public function get_chunk_size(): int {
		return 200;
	}

	/**
	 * Measured on this hardware at 26 hashes/second.
	 */
	public function get_rate_per_second(): float {
		return 26.0;
	}

	/**
	 * Scramble, revoke and log out.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Unused.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				$random_password = wp_generate_password( 64, true, true );

				// Silent. wp_update_user() would email every one of them.
				wp_set_password( $random_password, $user_id );
				unset( $random_password );

				\WP_Session_Tokens::get_instance( $user_id )->destroy_all();

				if ( class_exists( '\WP_Application_Passwords' ) ) {
					\WP_Application_Passwords::delete_all_application_passwords( $user_id );
				}

				$result->succeed();
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Delete the matched users.
 *
 * Uses `wp_delete_user()` and never raw SQL: it fires the hooks WooCommerce and
 * everything else depend on, removes usermeta, and handles content. Deleting
 * rows directly leaves orphaned meta and a corrupted install that surfaces
 * months later. The price is speed, and it is worth paying.
 */
class Delete_Users_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'delete';
	}

	/**
	 * Operator-facing name.
	 */
	public function get_label(): string {
		return __( 'Delete accounts', 'purge-user-accounts' );
	}

	/**
	 * One-sentence description for the confirmation screen.
	 */
	public function get_description(): string {
		return __( 'Permanently removes the accounts. This cannot be undone, and the plugin cannot restore them.', 'purge-user-accounts' );
	}

	/**
	 * As destructive as it gets.
	 */
	public function get_destructiveness(): int {
		return 100;
	}

	/**
	 * Not by any honest definition.
	 */
	public function is_reversible(): bool {
		return false;
	}

	/**
	 * Hook cost dominates.
	 */
	public function get_chunk_size(): int {
		return 50;
	}

	/**
	 * A deliberately pessimistic starting guess.
	 *
	 * Measured at 485/second deleting accounts with no content on a bare site,
	 * and documented at 10-50/second on a WooCommerce site with order history -
	 * the hooks, not our code, are the cost. The job refines this from observed
	 * throughput as soon as it has enough to go on.
	 */
	public function get_rate_per_second(): float {
		return 25.0;
	}

	/**
	 * Delete each user, reassigning or removing their content as instructed.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Requires `reassign_to`: a user ID, or
	 *                                      0 to delete their content.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$result = new Action_Result();

		// No default. In the UI this is a visible radio button; on the CLI it
		// must be typed. A silent default of "delete their content" that nobody
		// noticed is exactly the unnoticed destruction this plugin exists to
		// avoid.
		if ( ! array_key_exists( 'reassign_to', $args ) ) {
			foreach ( $user_ids as $user_id ) {
				$result->fail( $user_id, __( 'No decision was recorded about what to do with their content, so nothing was deleted.', 'purge-user-accounts' ) );
			}

			return $result;
		}

		$reassign_to = absint( $args['reassign_to'] );
		$reassign    = $reassign_to > 0 ? $reassign_to : null;

		foreach ( $user_ids as $user_id ) {
			try {
				$deleted = wp_delete_user( $user_id, $reassign );

				if ( $deleted ) {
					$result->succeed();
				} else {
					$result->fail( $user_id, __( 'WordPress declined to delete this account.', 'purge-user-accounts' ) );
				}
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}
