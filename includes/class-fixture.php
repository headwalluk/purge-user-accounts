<?php
/**
 * Development fixture generation.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Builds a synthetic user population with a known composition.
 *
 * Generated fixtures validate the engine. They cannot validate a rule, because
 * we generated the data to match it - only the real corpus can do that. See
 * dev-notes/09-safety-model.md §7.
 *
 * Baseline size is 40,000, matching the largest site on the fleet. 100,000 is a
 * stress case rather than the thing we tune against.
 */
class Fixture {

	/**
	 * Cohort weights. Keys are cohort ids, values are percentages.
	 *
	 * @var array<string,int>
	 */
	const COHORTS = array(
		'legit_customer' => 5,
		'legit_dormant'  => 15,
		'author'         => 3,
		'commenter'      => 2,
		'guest_then_reg' => 1,
		'academic'       => 1,
		'bot_throwaway'  => 60,
		'bot_plausible'  => 13,
	);

	/**
	 * Disposable domains used by the throwaway cohort.
	 *
	 * @var string[]
	 */
	const THROWAWAY_DOMAINS = array(
		'mailinator.com',
		'guerrillamail.com',
		'yopmail.com',
		'sharklasers.com',
		'3fb2.andreydildos.online',
		'xudaze.wisecook.xyz',
		'm0r.eloymail.top',
		'freesourcecodes.com',
	);

	/**
	 * Plausible domains used by the legitimate cohorts.
	 *
	 * @var string[]
	 */
	const REAL_DOMAINS = array( 'gmail.com', 'outlook.com', 'btinternet.com', 'somerealbusiness.co.uk' );

	/**
	 * Meta key marking a generated user.
	 *
	 * Fixture logins are deliberately shaped to look like real ones, so they
	 * cannot also serve as the marker for cleanup - the academic cohort
	 * (`2600001a`) matches neither of the obvious prefixes. An explicit flag is
	 * the only safe way to know what we created.
	 *
	 * @var string
	 */
	const FIXTURE_META_KEY = 'hwpua_is_fixture';

	/**
	 * Academic domains, which exercise the known pattern false positive.
	 *
	 * @var string[]
	 */
	const ACADEMIC_DOMAINS = array( 'student.gla.ac.uk', 'ac.uk', 'nhs.net', 'cam.ac.uk' );

	/**
	 * Whether fixture generation is permitted here.
	 *
	 * `WP_DEBUG` alone is too weak a gate for something that creates tens of
	 * thousands of users and deletes them again by login pattern. A site can
	 * carry WP_DEBUG and still be serving customers.
	 *
	 * So: refuse on a production environment unless the site has explicitly
	 * opted in with `HWPUA_ALLOW_FIXTURES`.
	 */
	public static function is_enabled(): bool {
		$is_enabled = false;

		if ( defined( 'HWPUA_ALLOW_FIXTURES' ) && HWPUA_ALLOW_FIXTURES ) {
			$is_enabled = true;
		} elseif ( 'production' !== wp_get_environment_type() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$is_enabled = true;
		}

		return $is_enabled;
	}

	/**
	 * Why fixture generation is refused, for the CLI error.
	 *
	 * Escaped because the same string is carried by a Run_Exception, which may
	 * be rendered in admin as well as printed by WP-CLI.
	 *
	 * @return string
	 */
	public static function get_disabled_reason(): string {
		return sprintf(
			'Fixture generation is disabled. This site reports environment "%s" with WP_DEBUG %s. Set WP_ENVIRONMENT_TYPE to development or staging, or define HWPUA_ALLOW_FIXTURES to override deliberately.',
			esc_html( wp_get_environment_type() ),
			( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'on' : 'off'
		);
	}

	/**
	 * Generate users up to a target, resuming from any previous progress.
	 *
	 * @param int           $target_total   Users to create in total.
	 * @param int           $batch_size     Users per batch.
	 * @param callable|null $on_batch  Called after each batch with (created, target).
	 * @return array<string,int> Counts per cohort.
	 * @throws Run_Exception When generation is not permitted.
	 */
	public static function generate( int $target_total, int $batch_size = 500, ?callable $on_batch = null ): array {
		if ( ! self::is_enabled() ) {
			throw new Run_Exception( self::get_disabled_reason() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- get_disabled_reason() escapes the only interpolated value.
		}

		self::speed_up_generation();

		$settings      = new Settings();
		$already_made  = $settings->get_int( OPT_FIXTURE_CREATED, 0 );
		$cohort_counts = $settings->get_array( OPT_FIXTURE_COHORTS, array() );

		while ( $already_made < $target_total ) {
			$batch_end = min( $target_total, $already_made + $batch_size );

			for ( $user_index = $already_made; $user_index < $batch_end; $user_index++ ) {
				$cohort  = self::pick_cohort( $user_index );
				$created = self::create_user( $cohort, $user_index );

				if ( $created ) {
					$cohort_counts[ $cohort ] = ( $cohort_counts[ $cohort ] ?? 0 ) + 1;
				}
			}

			$already_made = $batch_end;
			$settings->set_int( OPT_FIXTURE_CREATED, $already_made );
			$settings->set_array( OPT_FIXTURE_COHORTS, $cohort_counts );

			if ( null !== $on_batch ) {
				call_user_func( $on_batch, $already_made, $target_total );
			}
		}

		return $cohort_counts;
	}

	/**
	 * How many users the fixture actually has, versus how many were requested.
	 *
	 * @return array{requested:int,actual:int,shortfall:int}
	 */
	public static function get_shortfall(): array {
		global $wpdb;

		$settings  = new Settings();
		$requested = $settings->get_int( OPT_FIXTURE_CREATED, 0 );
		$actual    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::FIXTURE_META_KEY
			)
		);

		return array(
			'requested' => $requested,
			'actual'    => $actual,
			'shortfall' => max( 0, $requested - $actual ),
		);
	}

	/**
	 * Make generation fast enough to be practical.
	 *
	 * At the production bcrypt cost this is 38 ms per user - 25 minutes for
	 * 40,000, all of it hashing passwords nothing will ever authenticate
	 * against. Cost 4 brings it to roughly 28 seconds.
	 *
	 * @return void
	 */
	protected static function speed_up_generation(): void {
		add_filter( 'wp_hash_password_options', array( __CLASS__, 'use_cheap_hash' ), 10, 2 );
		add_filter( 'send_password_change_email', '__return_false' );
		add_filter( 'send_email_change_email', '__return_false' );
		add_filter( 'pre_wp_mail', '__return_false' );

		wp_defer_comment_counting( true );
		wp_suspend_cache_invalidation( true );
	}

	/**
	 * Use a deliberately cheap bcrypt cost for throwaway fixture passwords.
	 *
	 * @param array<string,mixed> $options   Hashing options.
	 * @param string              $algorithm Algorithm in use.
	 * @return array<string,mixed>
	 */
	public static function use_cheap_hash( array $options, string $algorithm ): array {
		if ( PASSWORD_BCRYPT === $algorithm ) {
			$options['cost'] = 4;
		}

		return $options;
	}

	/**
	 * Deterministically assign a cohort by position.
	 *
	 * Deterministic so a resumed run produces the same composition as an
	 * uninterrupted one.
	 *
	 * @param int $user_index Zero-based index of the user being created.
	 * @return string
	 */
	protected static function pick_cohort( int $user_index ): string {
		$position      = $user_index % 100;
		$running_total = 0;
		$chosen        = 'bot_plausible';

		foreach ( self::COHORTS as $cohort_id => $weight ) {
			$running_total += $weight;

			if ( $position < $running_total ) {
				$chosen = $cohort_id;
				break;
			}
		}

		return $chosen;
	}

	/**
	 * Create one user, plus whatever content its cohort implies.
	 *
	 * @param string $cohort     Cohort identifier.
	 * @param int    $user_index Zero-based index.
	 * @return bool Whether the user was created.
	 */
	protected static function create_user( string $cohort, int $user_index ): bool {
		$login = self::build_login( $cohort, $user_index );
		$email = self::build_email( $cohort, $user_index, $login );
		$years = self::build_registration_offset( $cohort, $user_index );

		$user_id = wp_insert_user(
			array(
				'user_login'      => $login,
				'user_email'      => $email,
				'user_pass'       => 'fixture-' . $user_index,
				'role'            => 'subscriber',
				'display_name'    => 'Fixture ' . $user_index,
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - $years ),
			)
		);

		$was_created = ! is_wp_error( $user_id );

		if ( $was_created ) {
			update_user_meta( (int) $user_id, self::FIXTURE_META_KEY, $cohort );
			self::add_cohort_activity( $cohort, (int) $user_id, $user_index );
		} else {
			// Even throwaway tooling records why something failed. A fixture
			// that quietly creates fewer users than asked makes every later
			// measurement wrong by an unknown amount.
			hwpua_log_error(
				sprintf( 'Fixture user %d (%s) could not be created: %s', $user_index, $login, $user_id->get_error_message() )
			);
		}

		return $was_created;
	}

	/**
	 * Give a user the activity its cohort implies.
	 *
	 * @param string $cohort     Cohort identifier.
	 * @param int    $user_id    Created user.
	 * @param int    $user_index Zero-based index.
	 * @return void
	 */
	protected static function add_cohort_activity( string $cohort, int $user_id, int $user_index ): void {
		if ( 'author' === $cohort ) {
			wp_insert_post(
				array(
					'post_author'  => $user_id,
					'post_title'   => 'Fixture post ' . $user_index,
					'post_content' => 'Generated for engine testing.',
					'post_status'  => 'publish',
					'post_type'    => 'post',
				)
			);
		}

		if ( 'commenter' === $cohort ) {
			wp_insert_comment(
				array(
					'comment_post_ID'  => 1,
					'user_id'          => $user_id,
					'comment_content'  => 'Fixture comment ' . $user_index,
					'comment_approved' => 1,
				)
			);
		}

		// Only cohorts that plausibly signed in get a login record. Everything
		// else is the "no record" population the login filters must handle.
		if ( in_array( $cohort, array( 'legit_customer', 'author', 'commenter' ), true ) ) {
			update_user_meta( $user_id, META_LAST_LOGIN, gmdate( 'Y-m-d H:i:s', time() - ( $user_index % 200 ) * DAY_IN_SECONDS ) );
		}
	}

	/**
	 * Build a login shaped like its cohort.
	 *
	 * @param string $cohort     Cohort identifier.
	 * @param int    $user_index Zero-based index.
	 * @return string
	 */
	protected static function build_login( string $cohort, int $user_index ): string {
		$login = '';

		switch ( $cohort ) {
			case 'bot_throwaway':
				// Matches the "generated username" rule: user_<blob>.
				$login = sprintf( 'user_%s%d', substr( md5( (string) $user_index ), 0, 8 ), $user_index );
				break;

			case 'academic':
				$login = sprintf( '%d%s', 2600000 + $user_index, chr( 97 + ( $user_index % 26 ) ) );
				break;

			default:
				$login = sprintf( 'fixture%s%d', chr( 97 + ( $user_index % 26 ) ), $user_index );
				break;
		}

		return $login;
	}

	/**
	 * Build an email shaped like its cohort.
	 *
	 * @param string $cohort     Cohort identifier.
	 * @param int    $user_index Zero-based index.
	 * @param string $login      The user's login.
	 * @return string
	 */
	protected static function build_email( string $cohort, int $user_index, string $login ): string {
		$domain = '';

		switch ( $cohort ) {
			case 'bot_throwaway':
				$domain = self::pick_domain( self::THROWAWAY_DOMAINS, $user_index );
				break;

			case 'academic':
				$domain = self::pick_domain( self::ACADEMIC_DOMAINS, $user_index );
				break;

			default:
				$domain = self::pick_domain( self::REAL_DOMAINS, $user_index );
				break;
		}

		return $login . '@' . $domain;
	}

	/**
	 * Choose a domain without coupling it to the cohort assignment.
	 *
	 * Cohorts are assigned by `$user_index % 100`, so a cohort occupying a
	 * single position has a CONSTANT index modulo any factor of 100. Selecting a
	 * domain with `$user_index % count()` therefore gave every academic user the
	 * same domain - 400 users all on one host, when four were intended. Hashing
	 * first decorrelates the two.
	 *
	 * @param string[] $domains    Domains to choose from.
	 * @param int      $user_index Zero-based index.
	 * @return string
	 */
	protected static function pick_domain( array $domains, int $user_index ): string {
		$mixed = crc32( 'hwpua-domain-' . $user_index );

		return $domains[ $mixed % count( $domains ) ];
	}

	/**
	 * Seconds to subtract from now for this user's registration date.
	 *
	 * @param string $cohort     Cohort identifier.
	 * @param int    $user_index Zero-based index.
	 * @return int
	 */
	protected static function build_registration_offset( string $cohort, int $user_index ): int {
		$offset_seconds = ( $user_index % 900 ) * DAY_IN_SECONDS;

		if ( 'legit_dormant' === $cohort ) {
			$offset_seconds += 3 * YEAR_IN_SECONDS;
		}

		return $offset_seconds;
	}

	/**
	 * Remove every fixture user and reset progress.
	 *
	 * @param int $batch_size Users to delete per batch.
	 * @return int Users removed.
	 */
	public static function destroy( int $batch_size = 1000 ): int {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$removed_total = 0;
		$has_more      = true;

		while ( $has_more ) {
			$fixture_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d",
					self::FIXTURE_META_KEY,
					$batch_size
				)
			);

			if ( empty( $fixture_ids ) ) {
				$has_more = false;
				continue;
			}

			foreach ( $fixture_ids as $fixture_id ) {
				if ( wp_delete_user( (int) $fixture_id ) ) {
					++$removed_total;
				}
			}
		}

		delete_option( OPT_FIXTURE_CREATED );
		delete_option( OPT_FIXTURE_COHORTS );

		return $removed_total;
	}
}
