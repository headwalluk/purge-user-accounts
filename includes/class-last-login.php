<?php
/**
 * Selection of the active last-login source.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Picks which last-login source the site will use, and reports its limits.
 *
 * Across the Headwall fleet, 69% of sites have no usable source at all. Where
 * one exists it usually belongs to another plugin and started recording long
 * after the site did. The filters that depend on this are the most useful in
 * the tool and the most dangerous, so the honest reporting matters more than
 * the sources themselves.
 */
class Last_Login {

	/**
	 * Sources from every available integration, ordered by priority.
	 *
	 * @var Last_Login_Source[]|null
	 */
	protected static ?array $sources = null;

	/**
	 * Cached coverage for the active source.
	 *
	 * @var Coverage_Report|null
	 */
	protected static ?Coverage_Report $coverage = null;

	/**
	 * Every available source, best first.
	 *
	 * @return Last_Login_Source[]
	 */
	public static function get_sources(): array {
		if ( null === self::$sources ) {
			$collected = array();

			foreach ( Integration_Registry::get_integrations() as $integration ) {
				if ( ! $integration->is_available() ) {
					continue;
				}

				$source = $integration->get_last_login_source();

				if ( $source instanceof Last_Login_Source ) {
					$collected[] = $source;
				}
			}

			usort(
				$collected,
				static fn( Last_Login_Source $first, Last_Login_Source $second ): int => $first->priority <=> $second->priority
			);

			self::$sources = $collected;
		}

		return self::$sources;
	}

	/**
	 * The source this site will use, or null when there is none.
	 *
	 * Combining sources is deliberately not done. Mixing an activity source with
	 * a login source produces a number whose meaning varies per user, and an
	 * "earliest record" that is no longer a single date. A tool whose central
	 * risk is false confidence should not manufacture a figure nobody can reason
	 * about. See dev-notes/06-last-login-providers.md §4.
	 *
	 * @return Last_Login_Source|null
	 */
	public static function get_active_source(): ?Last_Login_Source {
		$sources  = self::get_sources();
		$selected = $sources[0] ?? null;

		$settings         = new Settings();
		$preferred_source = $settings->get_string( OPT_LAST_LOGIN_SOURCE );

		if ( '' !== $preferred_source ) {
			foreach ( $sources as $source ) {
				if ( $source->id === $preferred_source ) {
					$selected = $source;
					break;
				}
			}
		}

		return $selected;
	}

	/**
	 * Whether any source is available at all.
	 */
	public static function has_source(): bool {
		return null !== self::get_active_source();
	}

	/**
	 * Coverage for the active source, or null when there is none.
	 *
	 * @return Coverage_Report|null
	 */
	public static function get_coverage(): ?Coverage_Report {
		if ( null === self::$coverage ) {
			$source = self::get_active_source();

			if ( null === $source ) {
				return null;
			}

			self::$coverage = $source->get_coverage();
		}

		return self::$coverage;
	}

	/**
	 * Why the login criteria cannot be used, or '' when they can.
	 *
	 * Disabled with the reason stated, never hidden and never silently returning
	 * zero matches.
	 */
	public static function get_unavailable_reason(): string {
		return self::has_source()
			? ''
			: __( 'No last-login data source is available on this site. WordPress does not record logins itself. This plugin has started recording them, so this criterion becomes usable as that history accumulates — until then, use the registration date criterion instead.', 'purge-user-accounts' );
	}

	/**
	 * Decide what can honestly be said about one user's last login.
	 *
	 * The single place this judgement is made. The results table and the CSV
	 * both call it, because two implementations of the Unknown/Never rule would
	 * eventually disagree - and the one that said "Never" when it meant
	 * "Unknown" would be the one that got somebody deleted.
	 *
	 * @param int    $user_id         User to describe.
	 * @param string $user_registered Registration date, MySQL datetime.
	 * @return array{state:string,label:string,timestamp:int}
	 */
	public static function describe_for_user( int $user_id, string $user_registered ): array {
		$source = self::get_active_source();

		if ( null === $source ) {
			return array(
				'state'     => 'untracked',
				'label'     => __( 'not tracked', 'purge-user-accounts' ),
				'timestamp' => 0,
			);
		}

		$timestamp = $source->to_timestamp( get_user_meta( $user_id, $source->meta_key, true ) );

		if ( $timestamp > 0 ) {
			return array(
				'state'     => 'seen',
				'label'     => gmdate( 'Y-m-d', $timestamp ),
				'timestamp' => $timestamp,
			);
		}

		$coverage = self::get_coverage();
		$earliest = null === $coverage ? '' : $coverage->earliest_record;

		// Registered before the source began: it was not watching, so it cannot
		// say. That is Unknown, not Never.
		if ( '' !== $earliest && $user_registered < $earliest ) {
			return array(
				'state'     => 'unknown',
				'label'     => __( 'Unknown', 'purge-user-accounts' ),
				'timestamp' => 0,
			);
		}

		return array(
			'state'     => 'never',
			'label'     => __( 'Never', 'purge-user-accounts' ),
			'timestamp' => 0,
		);
	}

	/**
	 * Reset caches. Test support, and after another plugin is activated.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$sources  = null;
		self::$coverage = null;
	}
}
