<?php
/**
 * One step in a run's execution plan.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * An ordered unit of work within a run.
 *
 * Only the seed stage adds rows. Every later stage may only remove them, which
 * is what makes resumption correct by construction: an interrupted refinement
 * has produced a superset of the right answer, never a subset.
 */
class Stage {

	/**
	 * Stage kind - one of the STAGE_* constants.
	 *
	 * @var string
	 */
	public string $kind;

	/**
	 * Namespaced filter id, or '' for seed and finalise.
	 *
	 * @var string
	 */
	public string $filter_id;

	/**
	 * Operator-facing description, shown against the progress bar.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Operator-supplied arguments for the filter.
	 *
	 * @var array<string,mixed>
	 */
	public array $args;

	/**
	 * Build a stage.
	 *
	 * @param string              $kind      Stage kind.
	 * @param string              $label     Operator-facing description.
	 * @param string              $filter_id Namespaced filter id, if any.
	 * @param array<string,mixed> $args      Filter arguments.
	 */
	public function __construct( string $kind, string $label, string $filter_id = '', array $args = array() ) {
		$this->kind      = $kind;
		$this->filter_id = $filter_id;
		$this->label     = $label;
		$this->args      = $args;
	}
}
