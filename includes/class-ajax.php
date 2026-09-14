<?php
/**
 * Browser-driven stepper endpoints.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * The AJAX surface the Build tab drives.
 *
 * Every endpoint verifies the nonce AND re-checks the capability: a nonce
 * proves the request came from our form, not that the sender may act.
 *
 * The server owns the cursor. The client sends only a run id - a client that
 * could specify its own cursor could skip guards.
 */
class Ajax {

	/**
	 * Register endpoints.
	 *
	 * @return void
	 */
	public function run(): void {
		add_action( 'wp_ajax_' . AJAX_START_RUN, array( $this, 'start_run' ) );
		add_action( 'wp_ajax_' . AJAX_STEP_RUN, array( $this, 'step_run' ) );
		add_action( 'wp_ajax_' . AJAX_ESTIMATE, array( $this, 'estimate' ) );
		add_action( 'wp_ajax_' . AJAX_PREFLIGHT, array( $this, 'preflight' ) );
		add_action( 'wp_ajax_' . AJAX_START_JOB, array( $this, 'start_job' ) );
		add_action( 'wp_ajax_' . AJAX_STEP_JOB, array( $this, 'step_job' ) );
		add_action( 'wp_ajax_' . AJAX_SAVE_PRESET, array( $this, 'save_preset' ) );
		add_action( 'wp_ajax_' . AJAX_DELETE_PRESET, array( $this, 'delete_preset' ) );
		add_action( 'wp_ajax_' . AJAX_IMPORT_PRESET, array( $this, 'import_preset' ) );
	}

	/**
	 * Reject the request unless it is both authentic and authorised.
	 *
	 * @return void
	 */
	protected function require_authorised_request(): void {
		if ( ! check_ajax_referer( NONCE_ACTION, NONCE_FIELD, false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session expired. Reload the page and try again — nothing was changed.', 'purge-user-accounts' ) ),
				403
			);
		}

		if ( ! Environment::current_user_can_operate() ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to purge user accounts on this site.', 'purge-user-accounts' ) ),
				403
			);
		}
	}

	/**
	 * Read the submitted filter specification.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function read_spec(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in require_authorised_request(); the value is JSON, so it is validated by json_decode() and Run_Builder::validate() rather than by a text sanitiser that would corrupt it.
		$raw_spec = isset( $_POST['spec'] ) ? wp_unslash( $_POST['spec'] ) : '';
		$decoded  = is_string( $raw_spec ) ? json_decode( $raw_spec, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Count matches without materialising a run.
	 *
	 * The Build tab offers this as an explicit button rather than a live count:
	 * every estimate is a full query, so it should be asked for, not implied.
	 *
	 * @return void
	 */
	public function estimate(): void {
		$this->require_authorised_request();

		try {
			$spec     = Run_Builder::validate( $this->read_spec() );
			$warnings = Run_Builder::detect_impossible_combinations( $spec );

			$run      = Run::create( $spec );
			$progress = array( 'done' => false );
			$guard    = 0;

			while ( empty( $progress['done'] ) && $guard < 10000 ) {
				$progress = Stepper::step( $run );
				++$guard;
			}

			$matched = $run->count_items();

			// An estimate is throwaway; it must not clutter History.
			$run->update( array( 'status' => RUN_STATUS_ABANDONED ) );

			wp_send_json_success(
				array(
					'matched'  => $matched,
					'total'    => $this->count_users(),
					'warnings' => $warnings,
				)
			);
		} catch ( Run_Exception $caught_error ) {
			wp_send_json_error( array( 'message' => $caught_error->getMessage() ), 400 );
		}
	}

	/**
	 * Create a run and return its plan.
	 *
	 * @return void
	 */
	public function start_run(): void {
		$this->require_authorised_request();

		try {
			$spec = Run_Builder::validate( $this->read_spec() );
			$run  = Run::create( $spec );
			$plan = Run_Builder::plan( $spec );

			wp_send_json_success(
				array(
					'run_id'      => $run->get_id(),
					'stage_total' => count( $plan ),
					'total'       => $this->count_users(),
					'warnings'    => Run_Builder::detect_impossible_combinations( $spec ),
				)
			);
		} catch ( Run_Exception $caught_error ) {
			wp_send_json_error( array( 'message' => $caught_error->getMessage() ), 400 );
		}
	}

	/**
	 * Advance a run by one chunk.
	 *
	 * @return void
	 */
	public function step_run(): void {
		$this->require_authorised_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$run    = Run::load( $run_id );

		if ( null === $run ) {
			wp_send_json_error(
				array( 'message' => __( 'That query could not be found. It may have been pruned.', 'purge-user-accounts' ) ),
				404
			);
		}

		$progress = Stepper::step( $run );

		if ( ! empty( $progress['aborted'] ) ) {
			wp_send_json_error( $progress, 500 );
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Read the run and action a job request refers to.
	 *
	 * @return array{run:Run,action:Action,args:array<string,mixed>}
	 */
	protected function read_job_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by require_authorised_request() before this runs.
		$run_id    = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$action_id = isset( $_POST['action_id'] ) ? sanitize_key( wp_unslash( $_POST['action_id'] ) ) : '';
		$raw_args  = isset( $_POST['args'] ) ? wp_unslash( $_POST['args'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; validated by json_decode() below and by each action's own argument handling, rather than by a text sanitiser that would corrupt it.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$run    = Run::load( $run_id );
		$action = Action_Registry::get_action( $action_id );

		if ( null === $run ) {
			wp_send_json_error( array( 'message' => __( 'That query could not be found.', 'purge-user-accounts' ) ), 404 );
		}

		if ( null === $action ) {
			wp_send_json_error( array( 'message' => __( 'That action is not available on this site.', 'purge-user-accounts' ) ), 400 );
		}

		$decoded = is_string( $raw_args ) ? json_decode( $raw_args, true ) : null;

		return array(
			'run'    => $run,
			'action' => $action,
			'args'   => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Return the computed pre-flight checklist.
	 *
	 * @return void
	 */
	public function preflight(): void {
		$this->require_authorised_request();

		$request = $this->read_job_request();

		wp_send_json_success( Preflight::build( $request['run'], $request['action'], $request['args'] ) );
	}

	/**
	 * Create a job, once the confirmation has been typed correctly.
	 *
	 * @return void
	 */
	public function start_job(): void {
		$this->require_authorised_request();

		$request = $this->read_job_request();
		$run     = $request['run'];
		$action  = $request['action'];

		$preflight = Preflight::build( $run, $action, $request['args'] );

		if ( empty( $preflight['can_proceed'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This cannot run yet — the checks above have to pass first.', 'purge-user-accounts' ) ),
				400
			);
		}

		// The typed confirmation is verified server-side. A client-side check
		// is a courtesy to the operator, not a control.
		if ( ! empty( $preflight['needs_confirmation'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$typed = isset( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';

			if ( $typed !== (string) $preflight['confirmation'] ) {
				wp_send_json_error(
					array( 'message' => __( 'The confirmation did not match. Nothing was changed.', 'purge-user-accounts' ) ),
					400
				);
			}
		}

		$export_path = Export_Directory::find_latest_for_run( $run->get_id() );

		try {
			$job = Job::create( $run, $action, $request['args'], '' === $export_path ? '' : basename( $export_path ) );
		} catch ( Run_Exception $caught_error ) {
			wp_send_json_error( array( 'message' => $caught_error->getMessage() ), 400 );
		}

		wp_send_json_success(
			array(
				'job_id'  => $job->get_id(),
				'total'   => $run->get_matched_count(),
				'dry_run' => ! empty( $request['args']['dry_run'] ),
			)
		);
	}

	/**
	 * Advance a job by one chunk.
	 *
	 * @return void
	 */
	public function step_job(): void {
		$this->require_authorised_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$job_id = isset( $_POST['job_id'] ) ? absint( wp_unslash( $_POST['job_id'] ) ) : 0;
		$job    = Job::load( $job_id );

		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'That job could not be found.', 'purge-user-accounts' ) ), 404 );
		}

		$progress = Job_Runner::step( $job );

		if ( ! empty( $progress['aborted'] ) ) {
			wp_send_json_error( $progress, 500 );
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Total users on the site.
	 *
	 * @return int
	 */
	protected function count_users(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	}

	/**
	 * Store the ticked criteria under a name.
	 *
	 * @return void
	 */
	public function save_preset(): void {
		$this->require_authorised_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in require_authorised_request().
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in require_authorised_request().
		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( '' === $label ) {
			wp_send_json_error( array( 'message' => __( 'Give the query a name before saving it.', 'purge-user-accounts' ) ), 400 );
		}

		try {
			$slug = Preset::save( $label, $this->read_spec(), $description );
		} catch ( Run_Exception $caught_error ) {
			wp_send_json_error( array( 'message' => $caught_error->getMessage() ), 400 );
		}

		wp_send_json_success(
			array(
				'slug'    => $slug,
				'presets' => Preset::get_all(),
				'message' => sprintf(
					/* translators: %s: preset name. */
					__( 'Saved as "%s".', 'purge-user-accounts' ),
					$label
				),
			)
		);
	}

	/**
	 * Forget a saved query.
	 *
	 * @return void
	 */
	public function delete_preset(): void {
		$this->require_authorised_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in require_authorised_request().
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

		if ( ! Preset::delete( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'That saved query no longer exists.', 'purge-user-accounts' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'presets' => Preset::get_all(),
				'message' => __( 'Deleted. Runs already made from it are unaffected.', 'purge-user-accounts' ),
			)
		);
	}

	/**
	 * Read a query exported from another site.
	 *
	 * A criterion this site cannot evaluate is refused rather than dropped. A
	 * preset that quietly lost its purchase filter would select customers.
	 *
	 * @return void
	 */
	public function import_preset(): void {
		$this->require_authorised_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in require_authorised_request(); the value is JSON, validated by Preset::parse_export() rather than by a text sanitiser that would corrupt it.
		$json = isset( $_POST['json'] ) ? wp_unslash( $_POST['json'] ) : '';

		try {
			$preset = Preset::parse_export( is_string( $json ) ? $json : '' );
		} catch ( Run_Exception $caught_error ) {
			wp_send_json_error( array( 'message' => $caught_error->getMessage() ), 400 );
		}

		$check = Preset::validate_against_site( $preset );

		if ( ! $check['ok'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'This query cannot run on this site, so it was not imported.', 'purge-user-accounts' ),
					'lines'   => $check['lines'],
				),
				400
			);
		}

		try {
			$slug = Preset::save( (string) $preset['label'], (array) $preset['filter_spec'], (string) $preset['description'] );
		} catch ( Run_Exception $caught_error ) {
			wp_send_json_error( array( 'message' => $caught_error->getMessage() ), 400 );
		}

		wp_send_json_success(
			array(
				'slug'    => $slug,
				'presets' => Preset::get_all(),
				'lines'   => $check['lines'],
				'message' => sprintf(
					/* translators: %s: preset name. */
					__( 'Imported "%s".', 'purge-user-accounts' ),
					(string) $preset['label']
				),
			)
		);
	}
}
