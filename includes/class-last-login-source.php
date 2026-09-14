<?php
/**
 * A source of last-login data, supplied by an integration.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Describes where last-login data comes from, and what it actually means.
 *
 * WordPress core records nothing about logins. Every source here belongs to
 * some other plugin, with its own meta key, its own value format, and - most
 * importantly - its own SEMANTICS and its own start date.
 *
 * See dev-notes/06-last-login-providers.md.
 */
class Last_Login_Source {

	const FORMAT_UNIX     = 'unix';
	const FORMAT_DATETIME = 'mysql_datetime';

	/** A record means the user authenticated. */
	const SEMANTICS_LOGIN = 'login';

	/** A record means the user was seen, which is not the same thing. */
	const SEMANTICS_ACTIVITY = 'activity';

	/**
	 * Stable identifier.
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * Operator-facing name.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Selection priority. Lower wins.
	 *
	 * @var int
	 */
	public int $priority;

	/**
	 * User meta key holding the timestamp.
	 *
	 * @var string
	 */
	public string $meta_key;

	/**
	 * How the stored value is encoded.
	 *
	 * @var string
	 */
	public string $value_format;

	/**
	 * Whether a record means a login or merely activity.
	 *
	 * @var string
	 */
	public string $semantics;

	/**
	 * A known start date, where the source can tell us one.
	 *
	 * @var string
	 */
	public string $earliest_hint;

	/**
	 * Build a source description.
	 *
	 * @param string $id            Stable identifier.
	 * @param string $label         Operator-facing name.
	 * @param int    $priority      Lower wins.
	 * @param string $meta_key      User meta key.
	 * @param string $value_format  One of the FORMAT_* constants.
	 * @param string $semantics     One of the SEMANTICS_* constants.
	 * @param string $earliest_hint Known start date in MySQL datetime form, if any.
	 */
	public function __construct(
		string $id,
		string $label,
		int $priority,
		string $meta_key,
		string $value_format,
		string $semantics,
		string $earliest_hint = ''
	) {
		$this->id            = $id;
		$this->label         = $label;
		$this->priority      = $priority;
		$this->meta_key      = $meta_key;
		$this->value_format  = $value_format;
		$this->semantics     = $semantics;
		$this->earliest_hint = $earliest_hint;
	}

	/**
	 * Whether a record means the user actually authenticated.
	 */
	public function means_login(): bool {
		return self::SEMANTICS_LOGIN === $this->semantics;
	}

	/**
	 * SQL expression converting the stored meta value to a comparable datetime.
	 *
	 * @param string $meta_alias Table alias for the usermeta row.
	 * @return string
	 */
	public function get_datetime_expression( string $meta_alias = 'llm' ): string {
		return self::FORMAT_UNIX === $this->value_format
			? "FROM_UNIXTIME({$meta_alias}.meta_value + 0)"
			: "{$meta_alias}.meta_value";
	}

	/**
	 * Turn a stored value into a Unix timestamp, rejecting implausible ones.
	 *
	 * Broken metadata must not become a trust signal: a value of 9999999999
	 * would otherwise read as a recent login and spare a bot. A day's allowance
	 * covers clock skew between systems.
	 *
	 * @param mixed $stored_value Raw meta value.
	 * @return int Timestamp, or 0 when absent or implausible.
	 */
	public function to_timestamp( $stored_value ): int {
		$timestamp = 0;

		if ( is_scalar( $stored_value ) && '' !== (string) $stored_value ) {
			if ( self::FORMAT_UNIX === $this->value_format ) {
				$timestamp = is_numeric( $stored_value ) ? (int) $stored_value : 0;
			} else {
				$parsed    = strtotime( (string) $stored_value . ' UTC' );
				$timestamp = false === $parsed ? 0 : $parsed;
			}
		}

		return ( $timestamp > 0 && $timestamp <= ( time() + DAY_IN_SECONDS ) ) ? $timestamp : 0;
	}

	/**
	 * Measure what this source actually knows about this site's users.
	 *
	 * @return Coverage_Report
	 */
	public function get_coverage(): Coverage_Report {
		global $wpdb;

		$users_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		$with_record = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != ''",
				$this->meta_key
			)
		);

		$datetime_expression = $this->get_datetime_expression( 'm' );
		$earliest_recorded   = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN({$datetime_expression}) FROM {$wpdb->usermeta} m WHERE m.meta_key = %s AND m.meta_value != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Expression built from our own constants.
				$this->meta_key
			)
		);

		// The hint wins when it predates the data: a plugin that started
		// recording on activation knows its own start date even if nobody has
		// logged in since.
		$earliest = $earliest_recorded;

		if ( '' !== $this->earliest_hint && ( '' === $earliest || $this->earliest_hint < $earliest ) ) {
			$earliest = $this->earliest_hint;
		}

		$unknown_cohort = 0;

		if ( '' !== $earliest ) {
			$unknown_cohort = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->users} u
					 WHERE u.user_registered < %s
					   AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->usermeta} m
						WHERE m.user_id = u.ID AND m.meta_key = %s AND m.meta_value != ''
					   )",
					$earliest,
					$this->meta_key
				)
			);
		}

		return new Coverage_Report(
			$users_total,
			$with_record,
			$users_total - $with_record,
			$earliest,
			$unknown_cohort
		);
	}
}

/**
 * What a source knows, and what it cannot know.
 *
 * The last field is the one that matters. It counts users the plugin must
 * describe as Unknown rather than Never, because they registered before the
 * source began recording. Reporting them as "never logged in" is how a purge
 * removes a decade of regulars.
 */
class Coverage_Report {

	/**
	 * Users on the site.
	 *
	 * @var int
	 */
	public int $users_total;

	/**
	 * Users carrying a record.
	 *
	 * @var int
	 */
	public int $users_with_record;

	/**
	 * Users with no record at all.
	 *
	 * @var int
	 */
	public int $users_without_record;

	/**
	 * Earliest record the source holds, in MySQL datetime form.
	 *
	 * @var string
	 */
	public string $earliest_record;

	/**
	 * Users with no record who registered before the source began.
	 *
	 * @var int
	 */
	public int $users_registered_before_earliest;

	/**
	 * Build a coverage report.
	 *
	 * @param int    $users_total                      Users on the site.
	 * @param int    $users_with_record                Users carrying a record.
	 * @param int    $users_without_record             Users with no record.
	 * @param string $earliest_record                  Earliest record held.
	 * @param int    $users_registered_before_earliest The unknown cohort.
	 */
	public function __construct(
		int $users_total,
		int $users_with_record,
		int $users_without_record,
		string $earliest_record,
		int $users_registered_before_earliest
	) {
		$this->users_total                      = $users_total;
		$this->users_with_record                = $users_with_record;
		$this->users_without_record             = $users_without_record;
		$this->earliest_record                  = $earliest_record;
		$this->users_registered_before_earliest = $users_registered_before_earliest;
	}

	/**
	 * Users who genuinely never logged in, as far as anything can tell.
	 *
	 * Those with no record who registered AFTER the source began: the source was
	 * watching, and saw nothing.
	 */
	public function get_confident_never_count(): int {
		return max( 0, $this->users_without_record - $this->users_registered_before_earliest );
	}

	/**
	 * Whether any user's status is genuinely unknowable from this source.
	 */
	public function has_unknown_cohort(): bool {
		return $this->users_registered_before_earliest > 0;
	}
}
