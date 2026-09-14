<?php
/**
 * Outcome of applying an action to one chunk.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * What happened to each user in a chunk.
 *
 * Successes are counted only - the operator's downloaded export is already the
 * record of who was targeted. Failures and skips each carry a reason, because a
 * job reporting "38,402 succeeded, 6 failed" must be able to say which six.
 */
class Action_Result {

	/**
	 * Users the action completed for.
	 *
	 * @var int
	 */
	public int $succeeded = 0;

	/**
	 * Per-user failures and skips.
	 *
	 * @var array<int,array{user_id:int,result:string,message:string}>
	 */
	public array $items = array();

	/**
	 * Record a success.
	 *
	 * @return void
	 */
	public function succeed(): void {
		++$this->succeeded;
	}

	/**
	 * Record a failure, with a reason.
	 *
	 * @param int    $user_id User affected.
	 * @param string $message Why it failed.
	 * @return void
	 */
	public function fail( int $user_id, string $message ): void {
		$this->items[] = array(
			'user_id' => $user_id,
			'result'  => JOB_ITEM_FAILED,
			'message' => $message,
		);
	}

	/**
	 * Record a deliberate skip, with a reason.
	 *
	 * @param int    $user_id User affected.
	 * @param string $message Why it was skipped.
	 * @return void
	 */
	public function skip( int $user_id, string $message ): void {
		$this->items[] = array(
			'user_id' => $user_id,
			'result'  => JOB_ITEM_SKIPPED,
			'message' => $message,
		);
	}

	/**
	 * How many failed.
	 */
	public function count_failed(): int {
		return $this->count_by_result( JOB_ITEM_FAILED );
	}

	/**
	 * How many were skipped.
	 */
	public function count_skipped(): int {
		return $this->count_by_result( JOB_ITEM_SKIPPED );
	}

	/**
	 * Count items of one result type.
	 *
	 * @param string $result_type One of the JOB_ITEM_* constants.
	 * @return int
	 */
	protected function count_by_result( string $result_type ): int {
		$total = 0;

		foreach ( $this->items as $item ) {
			if ( $item['result'] === $result_type ) {
				++$total;
			}
		}

		return $total;
	}

	/**
	 * Merge another chunk's result into this one.
	 *
	 * @param Action_Result $other Result to absorb.
	 * @return void
	 */
	public function absorb( Action_Result $other ): void {
		$this->succeeded += $other->succeeded;
		$this->items      = array_merge( $this->items, $other->items );
	}
}
