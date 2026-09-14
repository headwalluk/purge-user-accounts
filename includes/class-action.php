<?php
/**
 * Base class for bulk actions.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Something that can be done to a completed run.
 *
 * Delete is not special - it is one entry in the registry that happens to be
 * the most destructive. Actions are ordered by `get_destructiveness()`, and the
 * UI scales confirmation friction to match.
 *
 * See dev-notes/05-action-catalogue.md.
 */
abstract class Action {

	/**
	 * Stable identifier.
	 */
	abstract public function get_id(): string;

	/**
	 * Operator-facing name.
	 */
	abstract public function get_label(): string;

	/**
	 * What this actually does, in one sentence, for the confirmation screen.
	 */
	abstract public function get_description(): string;

	/**
	 * Apply the action to one chunk of users.
	 *
	 * Receives IDs that have already passed the guards for this chunk.
	 *
	 * @param int[]               $user_ids Users to act on.
	 * @param array<string,mixed> $args     Operator-supplied arguments.
	 * @return Action_Result
	 */
	abstract public function apply( array $user_ids, array $args ): Action_Result;

	/**
	 * 0-100. Drives ordering and how much friction the confirmation carries.
	 */
	public function get_destructiveness(): int {
		return 50;
	}

	/**
	 * Whether this can be undone by the plugin itself.
	 */
	public function is_reversible(): bool {
		return false;
	}

	/**
	 * Whether an export must exist before this may run.
	 */
	public function requires_export(): bool {
		return true;
	}

	/**
	 * Whether acting on users who can manage users is even offerable.
	 */
	public function allows_privileged_opt_in(): bool {
		return true;
	}

	/**
	 * What the operator must type to confirm.
	 *
	 * Typing the match count rather than a fixed word forces them to look at the
	 * number they are about to act on. "DELETE" can be typed without reading.
	 *
	 * @param Run $run Run being acted upon.
	 * @return string
	 */
	public function get_confirmation_phrase( Run $run ): string {
		return (string) $run->get_matched_count();
	}

	/**
	 * Whether the action can be used on this installation.
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Why the action is unavailable.
	 */
	public function get_unavailable_reason(): string {
		return '';
	}

	/**
	 * Users per chunk. Tuned to keep a request near ten seconds.
	 */
	public function get_chunk_size(): int {
		return 1000;
	}

	/**
	 * Measured users per second, used for the time estimate.
	 *
	 * An estimate shown before the operator commits is the difference between a
	 * considered decision and an abandoned job.
	 */
	public function get_rate_per_second(): float {
		return 500.0;
	}

	/**
	 * Arguments this action needs from the operator.
	 *
	 * @return array<string,mixed>
	 */
	public function get_fields(): array {
		return array();
	}
}
