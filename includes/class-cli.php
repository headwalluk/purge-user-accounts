<?php
/**
 * WP-CLI commands.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Development and measurement commands.
 *
 * The operator-facing commands - run, export, act - arrive in Milestone 12.
 * These exist to build and measure the test fixture, and are gated on WP_DEBUG.
 */
class Cli {

	/**
	 * Register commands with WP-CLI.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'purge-users generate-fixture', array( __CLASS__, 'generate_fixture' ) );
			\WP_CLI::add_command( 'purge-users destroy-fixture', array( __CLASS__, 'destroy_fixture' ) );
			\WP_CLI::add_command( 'purge-users benchmark', array( __CLASS__, 'benchmark' ) );
		}
	}

	/**
	 * Generate a synthetic user population.
	 *
	 * ## OPTIONS
	 *
	 * [--users=<count>]
	 * : How many users to create in total. Default 40000.
	 *
	 * [--batch-size=<count>]
	 * : Users per batch. Default 500.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function generate_fixture( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! Fixture::is_enabled() ) {
			\WP_CLI::error( Fixture::get_disabled_reason() );
		}

		$target_total = isset( $assoc_args['users'] ) ? absint( $assoc_args['users'] ) : 40000;
		$batch_size   = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 500;

		$progress_bar = \WP_CLI\Utils\make_progress_bar( 'Generating users', $target_total );
		$last_seen    = 0;

		$cohort_counts = Fixture::generate(
			$target_total,
			$batch_size,
			static function ( int $created, int $target ) use ( $progress_bar, &$last_seen ) {
				unset( $target );
				$progress_bar->tick( $created - $last_seen );
				$last_seen = $created;
			}
		);

		$progress_bar->finish();

		$rows = array();
		foreach ( $cohort_counts as $cohort_id => $cohort_count ) {
			$rows[] = array(
				'cohort' => $cohort_id,
				'users'  => $cohort_count,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'cohort', 'users' ) );
		\WP_CLI::success( 'Fixture ready.' );
	}

	/**
	 * Remove every fixture user.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function destroy_fixture( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		if ( ! Fixture::is_enabled() ) {
			\WP_CLI::error( Fixture::get_disabled_reason() );
		}

		$removed = Fixture::destroy();
		\WP_CLI::success( sprintf( 'Removed %d fixture users.', $removed ) );
	}

	/**
	 * Measure a run stage by stage.
	 *
	 * ## OPTIONS
	 *
	 * [--spec=<json>]
	 * : Filter specification as JSON. Defaults to an empty spec, which measures
	 *   the seed alone.
	 *
	 * [--explain]
	 * : Print the MySQL execution plan for the seed instead of running it.
	 *
	 * [--compare-strategies]
	 * : Time the three ways of expressing "has no comments" against each other.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public static function benchmark( array $args, array $assoc_args ): void {
		unset( $args );

		$raw_spec = array();

		if ( isset( $assoc_args['spec'] ) ) {
			$decoded  = json_decode( (string) $assoc_args['spec'], true );
			$raw_spec = is_array( $decoded ) ? $decoded : array();
		}

		try {
			if ( isset( $assoc_args['compare-strategies'] ) ) {
				$rows = Benchmark::compare_comment_strategies();
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'strategy', 'setup_ms', 'query_ms', 'total_ms', 'matched' ) );
			} elseif ( isset( $assoc_args['explain'] ) ) {
				$rows = Benchmark::explain_seed( $raw_spec );
				\WP_CLI\Utils\format_items( 'table', $rows, array_keys( (array) reset( $rows ) ) );
			} else {
				self::print_measurements( Benchmark::measure_run( $raw_spec ) );
			}
		} catch ( Run_Exception $caught_error ) {
			\WP_CLI::error( $caught_error->getMessage() );
		}
	}

	/**
	 * Render a measurement result.
	 *
	 * @param array<string,mixed> $measurements Benchmark output.
	 * @return void
	 */
	protected static function print_measurements( array $measurements ): void {
		\WP_CLI::log( sprintf( 'users on site : %s', number_format( (int) $measurements['users_total'] ) ) );
		\WP_CLI::log( sprintf( 'matched       : %s', number_format( (int) $measurements['matched'] ) ) );
		\WP_CLI::log( sprintf( 'status        : %s', $measurements['status'] ) );

		if ( '' !== (string) $measurements['failure'] ) {
			\WP_CLI::warning( $measurements['failure'] );
		}

		\WP_CLI::log( sprintf( 'steps         : %d', (int) $measurements['steps'] ) );
		\WP_CLI::log( sprintf( 'elapsed       : %.1f ms', (float) $measurements['elapsed_ms'] ) );
		\WP_CLI::log( sprintf( 'peak memory   : %.2f MB', (float) $measurements['peak_memory_mb'] ) );
		\WP_CLI::log( '' );

		$rows = array();
		foreach ( (array) $measurements['per_stage'] as $stage_kind => $stage_data ) {
			$rows[] = array(
				'stage' => $stage_kind,
				'steps' => $stage_data['steps'],
				'ms'    => sprintf( '%.1f', $stage_data['ms'] ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'stage', 'steps', 'ms' ) );
	}
}
