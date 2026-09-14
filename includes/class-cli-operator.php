<?php
/**
 * Operator-facing WP-CLI commands.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Run saved queries, export them, and act on them from the command line.
 *
 * Every command drives the SAME stepper as the browser - a plain loop instead
 * of AJAX - so the two paths cannot drift. That also means a job begun in the
 * browser can be finished here, which matters for anything running an hour.
 */
class Cli_Operator {

	/**
	 * Register with WP-CLI.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		foreach ( array(
			'doctor'        => 'doctor',
			'run'           => 'run_preset',
			'show'          => 'show_run',
			'export'        => 'export_run',
			'act'           => 'act',
			'resume'        => 'resume',
			'stop'          => 'stop',
			'list-runs'     => 'list_runs',
			'list-presets'  => 'list_presets',
			'list-actions'  => 'list_actions',
			'preset-export' => 'preset_export',
			'preset-import' => 'preset_import',
			'preset-delete' => 'preset_delete',
		) as $command => $method ) {
			\WP_CLI::add_command( 'purge-users ' . $command, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Report what this site supports.
	 *
	 * The first thing to run on an unfamiliar site, and the first thing to ask
	 * for in a bug report.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function doctor( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		\WP_CLI::log( sprintf( 'environment      : %s', wp_get_environment_type() ) );
		\WP_CLI::log( sprintf( 'supported        : %s', Environment::is_supported() ? 'yes' : 'NO — ' . Environment::get_unsupported_reason() ) );
		\WP_CLI::log( sprintf( 'tables installed : %s', Schema::is_installed() ? 'yes' : 'NO' ) );
		\WP_CLI::log( sprintf( 'users            : %s', number_format_i18n( Run_History::count_users() ) ) );
		\WP_CLI::log( sprintf( 'woocommerce      : %s', Environment::has_woocommerce() ? ( Environment::uses_hpos() ? 'yes (HPOS)' : 'yes (legacy store)' ) : 'no' ) );
		\WP_CLI::log( sprintf( 'pattern rules    : %d active', count( Pattern_Ruleset::get_rules() ) ) );
		\WP_CLI::log( sprintf( 'domain allowlist : %d entries', count( Domain_Allowlist::get_entries() ) ) );

		$export_path = Export_Directory::get_path();
		\WP_CLI::log( sprintf( 'export directory : %s%s', $export_path, Export_Directory::is_inside_web_root() ? ' (inside web root)' : '' ) );
		// Writability is per-user, so it can differ between the web server and
		// whoever is at the shell. Naming the user makes a "NO" here readable.
		$process_user = function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' )
			? (string) ( posix_getpwuid( posix_geteuid() )['name'] ?? '' )
			: '';

		\WP_CLI::log(
			sprintf(
				'export writable  : %s%s',
				Export_Directory::is_ready() ? 'yes' : 'NO',
				'' === $process_user ? '' : sprintf( ' (as %s)', $process_user )
			)
		);

		$source = Last_Login::get_active_source();

		if ( null === $source ) {
			\WP_CLI::warning( 'No last-login source. The login criteria are unavailable on this site.' );
		} else {
			$coverage = Last_Login::get_coverage();
			\WP_CLI::log( sprintf( 'last-login source: %s (%s)', $source->label, $source->semantics ) );
			\WP_CLI::log( sprintf( '  earliest record: %s', '' === $coverage->earliest_record ? 'nothing recorded' : $coverage->earliest_record ) );
			\WP_CLI::log( sprintf( '  with a record  : %s', number_format_i18n( $coverage->users_with_record ) ) );
			\WP_CLI::log( sprintf( '  UNKNOWN cohort : %s', number_format_i18n( $coverage->users_registered_before_earliest ) ) );
		}
	}

	/**
	 * Build a run from a saved preset.
	 *
	 * ## OPTIONS
	 *
	 * <preset>
	 * : Slug of the preset to run.
	 *
	 * [--porcelain]
	 * : Print only the run ID.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function run_preset( array $args, array $assoc_args ): void {
		$slug   = $args[0] ?? '';
		$preset = Preset::get( $slug );

		if ( null === $preset ) {
			\WP_CLI::error( sprintf( 'No preset named "%s". Try: wp purge-users list-presets', $slug ) );
		}

		$check = Preset::validate_against_site( $preset );

		foreach ( $check['lines'] as $line ) {
			if ( 'error' === $line['level'] ) {
				\WP_CLI::log( '  ✗ ' . $line['text'] );
			} elseif ( 'warn' === $line['level'] ) {
				\WP_CLI::log( '  ⚠ ' . $line['text'] );
			}
		}

		if ( ! $check['ok'] ) {
			\WP_CLI::error( 'This preset cannot run here. Nothing was selected.' );
		}

		try {
			$run  = Run::create( Run_Builder::validate( (array) $preset['filter_spec'] ), $slug );
			$plan = Run_Builder::plan( $run->get_filter_spec() );
		} catch ( Run_Exception $caught_error ) {
			\WP_CLI::error( $caught_error->getMessage() );
		}

		$is_quiet = isset( $assoc_args['porcelain'] );

		if ( ! $is_quiet ) {
			\WP_CLI::log( sprintf( 'Building run #%d from "%s" — %d stages', $run->get_id(), $slug, count( $plan ) ) );
		}

		// A stage is chunked, so it reports many times. Hold each stage's last
		// count and print it only when the stage changes, or a 100k-user seed
		// would scroll a hundred near-identical lines past the operator.
		$progress   = array( 'done' => false );
		$steps      = 0;
		$open_label = '';
		$open_kind  = '';
		$open_count = 0;

		while ( empty( $progress['done'] ) && $steps < 100000 ) {
			$progress = Stepper::step( $run );
			++$steps;

			if ( ! empty( $progress['aborted'] ) ) {
				break;
			}

			$stage_label = (string) ( $progress['stage_label'] ?? '' );

			if ( ! $is_quiet && '' !== $open_label && $stage_label !== $open_label ) {
				\WP_CLI::log( self::describe_stage( $open_label, $open_kind, $open_count ) );
			}

			$open_label = $stage_label;
			$open_kind  = (string) ( $progress['stage_kind'] ?? '' );
			$open_count = (int) ( $progress['current_count'] ?? 0 );
		}

		if ( ! $is_quiet && '' !== $open_label ) {
			\WP_CLI::log( self::describe_stage( $open_label, $open_kind, $open_count ) );
		}

		if ( ! empty( $progress['aborted'] ) ) {
			\WP_CLI::error( $progress['message'] );
		}

		if ( $is_quiet ) {
			\WP_CLI::line( (string) $run->get_id() );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Run #%d complete. %s of %s users matched.',
				$run->get_id(),
				number_format_i18n( $run->get_matched_count() ),
				number_format_i18n( Run_History::count_users() )
			)
		);
		\WP_CLI::log( sprintf( 'Export: wp purge-users export %d', $run->get_id() ) );
		\WP_CLI::log( sprintf( 'Act:    wp purge-users act %d --action=block-signin', $run->get_id() ) );
	}

	/**
	 * One line of stage progress.
	 *
	 * A prepare stage has not selected anything yet, so reporting its running
	 * total as "0 matched" would read as a result rather than a preliminary.
	 *
	 * @param string $stage_label Stage name.
	 * @param string $stage_kind  One of the STAGE_* constants.
	 * @param int    $matched     Rows held by the run after this stage.
	 * @return string
	 */
	protected static function describe_stage( string $stage_label, string $stage_kind, int $matched ): string {
		return STAGE_PREPARE === $stage_kind
			? sprintf( '  %s', $stage_label )
			: sprintf( '  %-44s %s matched', $stage_label, number_format_i18n( $matched ) );
	}

	/**
	 * Summarise a run and sample its matches.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Run to show.
	 *
	 * [--limit=<count>]
	 * : Sample size. Default 20.
	 *
	 * [--random]
	 * : Sample randomly rather than by ID. The first page of an ID-ordered
	 *   result correlates with signup date, so it looks uniform whether or not
	 *   the query is right.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function show_run( array $args, array $assoc_args ): void {
		global $wpdb;

		$run = Run::load( absint( $args[0] ?? 0 ) );

		if ( null === $run ) {
			\WP_CLI::error( 'No such run.' );
		}

		\WP_CLI::log( sprintf( 'Run #%d — %s', $run->get_id(), Run_History::describe_status( $run ) ) );
		\WP_CLI::log( sprintf( 'Criteria: %s', Run_History::describe_spec( $run ) ) );
		\WP_CLI::log( sprintf( 'Matched : %s', number_format_i18n( $run->get_matched_count() ) ) );

		$reasons = Run_History::summarise_reasons( $run );

		if ( count( $reasons ) > 1 ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Why they matched:' );

			foreach ( $reasons as $reason ) {
				\WP_CLI::log( sprintf( '  %-56s %s', mb_substr( (string) $reason['reason'], 0, 54 ), number_format_i18n( (int) $reason['total'] ) ) );
			}
		}

		$limit       = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;
		$order       = isset( $assoc_args['random'] ) ? 'RAND()' : 'ri.user_id';
		$items_table = Schema::table( TABLE_RUN_ITEMS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants; ordering is an internal allowlist.
		$sample_sql = "SELECT u.ID, u.user_login, u.user_email, ri.matched_rule
			FROM {$items_table} ri JOIN {$wpdb->users} u ON u.ID = ri.user_id
			WHERE ri.run_id = %d ORDER BY {$order} LIMIT %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $wpdb->prepare( $sample_sql, $run->get_id(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared here with bound values.

		if ( ! empty( $rows ) ) {
			\WP_CLI::log( '' );
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'user_login', 'user_email', 'matched_rule' ) );
		}
	}

	/**
	 * Write a run's CSV.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Run to export.
	 *
	 * [--file=<path>]
	 * : Also place a copy here. The export directory keeps its own copy either
	 *   way, because a destructive action will not run without one.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function export_run( array $args, array $assoc_args ): void {
		$run = Run::load( absint( $args[0] ?? 0 ) );

		if ( null === $run ) {
			\WP_CLI::error( 'No such run.' );
		}

		try {
			$path = Csv_Export::write( $run );
		} catch ( Run_Exception $caught_error ) {
			\WP_CLI::error( $caught_error->getMessage() );
		}

		\WP_CLI::success( sprintf( '%s rows written to %s', number_format_i18n( $run->get_matched_count() ), $path ) );

		if ( ! isset( $assoc_args['file'] ) ) {
			\WP_CLI::warning( 'That file is deleted from the server after six hours. Copy it somewhere safe — it is the only record of who was in this result.' );
			return;
		}

		$destination = (string) $assoc_args['file'];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- A CLI-supplied path outside the uploads tree; WP_Filesystem would ask for credentials that do not exist at the shell.
		if ( ! copy( $path, $destination ) ) {
			\WP_CLI::error( sprintf( 'The export was written, but it could not be copied to %s.', $destination ) );
		}

		Export_Directory::harden_file( $destination );
		\WP_CLI::success( sprintf( 'Copied to %s', $destination ) );
	}

	/**
	 * Apply an action to a run.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Run to act on.
	 *
	 * --action=<action>
	 * : Action to apply. See: wp purge-users list-actions
	 *
	 * [--reassign=<user-id>]
	 * : REQUIRED for delete. The user to inherit any content, or 0 to delete it
	 *   along with the accounts. There is deliberately no default.
	 *
	 * [--dry-run]
	 * : Run every check and report, changing nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt. Required for a destructive action in a
	 *   non-interactive shell.
	 *
	 * [--allow-privileged]
	 * : Also act on users who can manage other users. The current user and the
	 *   last administrator are still protected.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function act( array $args, array $assoc_args ): void {
		$run    = Run::load( absint( $args[0] ?? 0 ) );
		$action = Action_Registry::get_action( (string) ( $assoc_args['action'] ?? '' ) );

		if ( null === $run ) {
			\WP_CLI::error( 'No such run.' );
		}

		if ( null === $action ) {
			\WP_CLI::error( 'Unknown action. See: wp purge-users list-actions' );
		}

		$action_args = array();

		if ( isset( $assoc_args['dry-run'] ) ) {
			$action_args['dry_run'] = true;
		}

		if ( isset( $assoc_args['allow-privileged'] ) ) {
			$action_args['allow_privileged'] = true;
		}

		// No default, on purpose. In the UI this is a visible radio button; here
		// it must be typed. A silent "delete their content" nobody noticed is
		// exactly the unnoticed destruction this plugin exists to avoid.
		if ( 'delete' === $action->get_id() ) {
			if ( ! isset( $assoc_args['reassign'] ) ) {
				\WP_CLI::error( 'Deleting requires --reassign=<user-id>, or --reassign=0 to delete their content too. There is no default.' );
			}

			$action_args['reassign_to'] = absint( $assoc_args['reassign'] );
		}

		$preflight = Preflight::build( $run, $action, $action_args );

		\WP_CLI::log( sprintf( '%s — %s accounts, %s', $action->get_label(), number_format_i18n( $preflight['matched'] ), $preflight['estimate_text'] ) );
		\WP_CLI::log( '' );

		foreach ( $preflight['checks'] as $check ) {
			$marker = array(
				Preflight::LEVEL_OK    => '  ✓ ',
				Preflight::LEVEL_WARN  => '  ⚠ ',
				Preflight::LEVEL_BLOCK => '  ✗ ',
			)[ $check['level'] ] ?? '  • ';

			\WP_CLI::log( $marker . $check['text'] );
		}

		\WP_CLI::log( '' );

		if ( empty( $preflight['can_proceed'] ) ) {
			\WP_CLI::error( 'The checks above must pass first. Nothing was changed.' );
		}

		if ( ! empty( $preflight['needs_confirmation'] ) && empty( $action_args['dry_run'] ) ) {
			\WP_CLI::confirm(
				sprintf( 'This cannot be undone. Really apply "%s" to %s accounts?', $action->get_label(), number_format_i18n( $preflight['matched'] ) ),
				$assoc_args
			);
		}

		$export_path = Export_Directory::find_latest_for_run( $run->get_id() );

		try {
			$job = Job::create( $run, $action, $action_args, '' === $export_path ? '' : basename( $export_path ) );
		} catch ( Run_Exception $caught_error ) {
			\WP_CLI::error( $caught_error->getMessage() );
		}

		self::drive_job( $job, ! empty( $action_args['dry_run'] ) );
	}

	/**
	 * Continue an interrupted job.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : Job to resume.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function resume( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$job = Job::load( absint( $args[0] ?? 0 ) );

		if ( null === $job ) {
			\WP_CLI::error( 'No such job.' );
		}

		if ( $job->is_finished() ) {
			\WP_CLI::success( 'That job has already finished.' );
			return;
		}

		if ( JOB_STATUS_CANCELLED === $job->get_status() ) {
			try {
				$job->update( array( 'status' => JOB_STATUS_RUNNING ) );
			} catch ( Run_Exception $caught_error ) {
				\WP_CLI::error( $caught_error->getMessage() );
			}
		}

		self::drive_job( $job, ! empty( $job->get_args()['dry_run'] ) );
	}

	/**
	 * Halt a running job, leaving it resumable.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : Job to stop.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function stop( array $args, array $assoc_args ): void {
		unset( $assoc_args );

		$job = Job::load( absint( $args[0] ?? 0 ) );

		if ( null === $job ) {
			\WP_CLI::error( 'No such job.' );
		}

		if ( $job->is_finished() ) {
			\WP_CLI::success( 'That job has already finished.' );
			return;
		}

		$job->cancel();
		\WP_CLI::success( sprintf( 'Job #%d stopped. Resume with: wp purge-users resume %d', $job->get_id(), $job->get_id() ) );
	}

	/**
	 * Advance a job to completion, reporting as it goes.
	 *
	 * @param Job  $job        Job to drive.
	 * @param bool $is_dry_run Whether nothing is actually changing.
	 * @return void
	 */
	protected static function drive_job( Job $job, bool $is_dry_run ): void {
		$job_id         = $job->get_id();
		$progress       = array( 'done' => false );
		$steps          = 0;
		$last_processed = -1;
		$last_report    = 0.0;

		while ( empty( $progress['done'] ) && $steps < 1000000 ) {
			$current = Job::load( $job_id );

			if ( null === $current ) {
				\WP_CLI::error( 'The job disappeared while running.' );
			}

			$progress = Job_Runner::step( $current );
			++$steps;

			if ( ! empty( $progress['aborted'] ) ) {
				\WP_CLI::error( $progress['message'] );
			}

			// Chunks are small - delete runs 50 at a time - so reporting every
			// one would scroll thousands of lines on a large site. Speak once a
			// second, and always for the last chunk.
			$is_last = ! empty( $progress['done'] );

			if ( (int) $progress['processed'] === $last_processed ) {
				continue;
			}

			if ( ! $is_last && microtime( true ) - $last_report < 1.0 ) {
				continue;
			}

			$last_processed = (int) $progress['processed'];
			$last_report    = microtime( true );

			\WP_CLI::log(
				sprintf(
					'  %s / %s — %s done, %s skipped, %s failed',
					number_format_i18n( (int) $progress['processed'] ),
					number_format_i18n( (int) $progress['total'] ),
					number_format_i18n( (int) $progress['succeeded'] ),
					number_format_i18n( (int) $progress['skipped'] ),
					number_format_i18n( (int) $progress['failed'] )
				)
			);
		}

		if ( ! empty( $progress['stopped'] ) ) {
			\WP_CLI::warning( sprintf( 'Job #%d was stopped before it finished. Resume with: wp purge-users resume %d', $job_id, $job_id ) );
			return;
		}

		if ( $is_dry_run ) {
			\WP_CLI::success( sprintf( 'Dry run finished — nothing was changed. Job #%d.', $job_id ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Job #%d finished.', $job_id ) );
	}

	/**
	 * List recent runs.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Show only runs with this status: building, complete or failed.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml. Default table.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function list_runs( array $args, array $assoc_args ): void {
		unset( $args );

		$known_statuses = array( RUN_STATUS_BUILDING, RUN_STATUS_COMPLETE, RUN_STATUS_FAILED, RUN_STATUS_ABANDONED );
		$wanted_status  = isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : '';
		$rows           = array();

		// An unrecognised status would otherwise report "no runs", which reads
		// as an answer rather than as a typo.
		if ( '' !== $wanted_status && ! in_array( $wanted_status, $known_statuses, true ) ) {
			\WP_CLI::error( sprintf( 'Unknown status "%s". Try one of: %s', $wanted_status, implode( ', ', $known_statuses ) ) );
		}

		foreach ( Run_History::get_recent( 25 ) as $row ) {
			$run = new Run( $row );

			if ( '' !== $wanted_status && $run->get_status() !== $wanted_status ) {
				continue;
			}

			$rows[] = array(
				'id'       => $run->get_id(),
				'status'   => Run_History::describe_status( $run ),
				'matched'  => $run->is_complete() ? number_format_i18n( $run->get_matched_count() ) : '—',
				'created'  => (string) $row['created_at'],
				'criteria' => mb_substr( Run_History::describe_spec( $run ), 0, 52 ),
			);
		}

		if ( empty( $rows ) ) {
			\WP_CLI::log( '' === $wanted_status ? 'Nothing has been run yet.' : sprintf( 'No runs with status "%s".', $wanted_status ) );
			return;
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'id', 'status', 'matched', 'created', 'criteria' )
		);
	}

	/**
	 * List saved presets.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function list_presets( array $args, array $assoc_args ): void {
		unset( $args );

		$rows = array();

		foreach ( Preset::get_all() as $slug => $preset ) {
			$check  = Preset::validate_against_site( $preset );
			$rows[] = array(
				'slug'        => $slug,
				'label'       => (string) ( $preset['label'] ?? $slug ),
				'criteria'    => count( (array) ( $preset['filter_spec'] ?? array() ) ),
				'needs'       => implode( ',', Preset::get_required_integrations( $preset ) ),
				'usable_here' => $check['ok'] ? 'yes' : 'NO',
			);
		}

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'No presets saved. Build a query in the admin screen and save it as one.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'slug', 'label', 'criteria', 'needs', 'usable_here' )
		);
	}

	/**
	 * Print a preset as JSON, for moving it to another site.
	 *
	 * ## OPTIONS
	 *
	 * <preset>
	 * : Slug of the preset to export.
	 *
	 * [--file=<path>]
	 * : Write to this file instead of standard output.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function preset_export( array $args, array $assoc_args ): void {
		$slug = $args[0] ?? '';
		$json = Preset::export( $slug );

		if ( '' === $json ) {
			\WP_CLI::error( sprintf( 'No preset named "%s".', $slug ) );
		}

		if ( ! isset( $assoc_args['file'] ) ) {
			\WP_CLI::line( $json );
			return;
		}

		$file_path = (string) $assoc_args['file'];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A CLI-supplied path outside the uploads tree; WP_Filesystem would ask for credentials that do not exist at the shell.
		if ( false === file_put_contents( $file_path, $json ) ) {
			\WP_CLI::error( sprintf( 'Could not write %s.', $file_path ) );
		}

		\WP_CLI::success( sprintf( 'Written to %s', $file_path ) );
	}

	/**
	 * Read a preset exported from another site.
	 *
	 * Reports what the preset needs before storing it. A criterion this site
	 * cannot evaluate is refused rather than dropped: a preset that quietly
	 * lost its purchase filter would select and delete customers.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the exported JSON, or - to read standard input.
	 *
	 * [--dry-run]
	 * : Report what would be imported, storing nothing.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function preset_import( array $args, array $assoc_args ): void {
		$file_path = (string) ( $args[0] ?? '' );

		if ( '-' === $file_path ) {
			$json = (string) file_get_contents( 'php://stdin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the CLI's own standard input.
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A CLI-supplied path; WP_Filesystem would ask for credentials that do not exist at the shell.
			$json = is_readable( $file_path ) ? (string) file_get_contents( $file_path ) : '';

			if ( '' === $json ) {
				\WP_CLI::error( sprintf( 'Could not read %s.', $file_path ) );
			}
		}

		try {
			$preset = Preset::parse_export( $json );
		} catch ( Run_Exception $caught_error ) {
			\WP_CLI::error( $caught_error->getMessage() );
		}

		\WP_CLI::log( sprintf( '%s — %d criteria', (string) $preset['label'], count( (array) $preset['filter_spec'] ) ) );

		$check = Preset::validate_against_site( $preset );

		foreach ( $check['lines'] as $line ) {
			$marker = array(
				'ok'    => '  ✓ ',
				'warn'  => '  ⚠ ',
				'error' => '  ✗ ',
			)[ $line['level'] ] ?? '  • ';

			\WP_CLI::log( $marker . $line['text'] );
		}

		if ( ! $check['ok'] ) {
			\WP_CLI::error( 'This preset cannot run here, so it was not imported.' );
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			\WP_CLI::success( 'The preset is usable here. Nothing was stored — this was a dry run.' );
			return;
		}

		try {
			$slug = Preset::save( (string) $preset['label'], (array) $preset['filter_spec'], (string) $preset['description'] );
		} catch ( Run_Exception $caught_error ) {
			\WP_CLI::error( $caught_error->getMessage() );
		}

		\WP_CLI::success( sprintf( 'Imported as "%s". Run it with: wp purge-users run %s', $slug, $slug ) );
	}

	/**
	 * Forget a saved preset.
	 *
	 * ## OPTIONS
	 *
	 * <preset>
	 * : Slug of the preset to delete.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function preset_delete( array $args, array $assoc_args ): void {
		$slug = (string) ( $args[0] ?? '' );

		if ( null === Preset::get( $slug ) ) {
			\WP_CLI::error( sprintf( 'No preset named "%s".', $slug ) );
		}

		\WP_CLI::confirm( sprintf( 'Delete the preset "%s"? Runs already made from it are unaffected.', $slug ), $assoc_args );
		Preset::delete( $slug );
		\WP_CLI::success( sprintf( 'Deleted "%s".', $slug ) );
	}

	/**
	 * List available actions, least destructive first.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function list_actions( array $args, array $assoc_args ): void {
		unset( $args );

		$rows = array();

		foreach ( Action_Registry::get_actions() as $action ) {
			$rows[] = array(
				'action'     => $action->get_id(),
				'label'      => $action->get_label(),
				'risk'       => $action->get_destructiveness(),
				'reversible' => $action->is_reversible() ? 'yes' : 'no',
				'export'     => $action->requires_export() ? 'required' : '—',
			);
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'action', 'label', 'risk', 'reversible', 'export' )
		);
	}
}
