<?php
/**
 * Operator-controlled email domain allowlist.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Domains whose addresses are never treated as bad-signup pattern matches.
 *
 * Scoped deliberately: this suppresses pattern matches only. It does not spare
 * users from any other criterion. A list that quietly removed users from every
 * query would be a hidden protections layer, and the result set must be exactly
 * what the query asked for.
 *
 * See dev-notes/07-email-pattern-engine.md §9.
 */
class Domain_Allowlist {

	/**
	 * Parsed entries for this request.
	 *
	 * @var string[]|null
	 */
	protected static ?array $entries = null;

	/**
	 * The active allowlist entries.
	 *
	 * @return string[]
	 */
	public static function get_entries(): array {
		if ( null === self::$entries ) {
			$settings      = new Settings();
			$parse_result  = self::parse( $settings->get_string( OPT_DOMAIN_ALLOWLIST ) );
			self::$entries = $parse_result['entries'];
		}

		return self::$entries;
	}

	/**
	 * Parse operator-entered text into normalised domain entries.
	 *
	 * `*.nhs.uk` and `nhs.uk` mean the same thing - the domain and everything
	 * beneath it. The wildcard form is accepted because operators will type it,
	 * and normalised away here.
	 *
	 * @param string $raw_text Textarea contents.
	 * @return array{entries:string[],problems:string[]}
	 */
	public static function parse( string $raw_text ): array {
		$entries  = array();
		$problems = array();

		foreach ( preg_split( '/\R/', $raw_text ) as $line_index => $raw_line ) {
			$trimmed = trim( $raw_line );

			if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) ) {
				continue;
			}

			$candidate = strtolower( trim( ltrim( $trimmed, '*' ), ". \t" ) );
			$candidate = self::to_ascii( $candidate );

			if ( ! self::is_valid_domain( $candidate ) ) {
				$problems[] = sprintf(
					/* translators: 1: line number, 2: the entry as typed. */
					__( 'Line %1$d: "%2$s" is not a valid domain. A bare top-level domain would allowlist far more than you intend.', 'purge-user-accounts' ),
					$line_index + 1,
					$trimmed
				);
				continue;
			}

			$entries[ $candidate ] = true;
		}

		return array(
			'entries'  => array_keys( $entries ),
			'problems' => $problems,
		);
	}

	/**
	 * Normalise an internationalised domain to ASCII.
	 *
	 * Without this a punycode address slips past a plain-text allowlist, and an
	 * operator typing a unicode domain never matches anything.
	 *
	 * @param string $domain Domain as entered or extracted.
	 * @return string
	 */
	protected static function to_ascii( string $domain ): string {
		$normalised = $domain;

		if ( '' !== $domain && function_exists( 'idn_to_ascii' ) ) {
			$ascii_form = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );

			if ( false !== $ascii_form ) {
				$normalised = strtolower( $ascii_form );
			}
		}

		return $normalised;
	}

	/**
	 * Whether a string is a syntactically valid multi-label domain.
	 *
	 * @param string $candidate Candidate domain.
	 * @return bool
	 */
	protected static function is_valid_domain( string $candidate ): bool {
		return strlen( $candidate ) <= 253
			&& 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $candidate );
	}

	/**
	 * Extract the domain half of an email address, normalised.
	 *
	 * Uses the LAST `@`: a quoted local part may legitimately contain one.
	 *
	 * @param string $email_address Address to inspect.
	 * @return string
	 */
	public static function get_email_domain( string $email_address ): string {
		$at_position = strrpos( $email_address, '@' );
		$domain      = false === $at_position
			? ''
			: strtolower( trim( substr( $email_address, $at_position + 1 ), ". \t" ) );

		return self::to_ascii( $domain );
	}

	/**
	 * Whether an address sits on an allowlisted domain.
	 *
	 * Suffix match on a dot boundary, which is what stops the obvious spoofs:
	 * `notnhs.uk` and `nhs.uk.attacker.com` are both denied against `nhs.uk`.
	 *
	 * @param string        $email_address Address to test.
	 * @param string[]|null $entries       Entries to test against, or null for the stored list.
	 * @return bool
	 */
	public static function is_allowed( string $email_address, ?array $entries = null ): bool {
		$entries      = null === $entries ? self::get_entries() : $entries;
		$email_domain = self::get_email_domain( $email_address );
		$is_allowed   = false;

		if ( '' !== $email_domain ) {
			foreach ( $entries as $entry ) {
				if ( $email_domain === $entry
					|| ( strlen( $email_domain ) > strlen( $entry ) && str_ends_with( $email_domain, '.' . $entry ) ) ) {
					$is_allowed = true;
					break;
				}
			}
		}

		return $is_allowed;
	}

	/**
	 * Count how many of this site's users each entry would cover.
	 *
	 * A far better guardrail than an abstract public-suffix rule, because
	 * `ac.uk` IS a public suffix and is exactly what a university client needs.
	 * An operator typing `co.uk` sees the number and the mistake is self-evident.
	 *
	 * Runs the real matcher rather than an approximating SQL LIKE: two
	 * implementations would drift, and a preview that disagrees with runtime is
	 * worse than no preview.
	 *
	 * @param string[] $entries    Entries to measure.
	 * @param int      $chunk_size Users to read per batch.
	 * @return array<string,int> Entry => matching user count.
	 */
	public static function count_matching_users( array $entries, int $chunk_size = 5000 ): array {
		global $wpdb;

		$counts = array_fill_keys( $entries, 0 );
		$cursor = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, user_email FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT %d",
					$cursor,
					$chunk_size
				)
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				foreach ( $entries as $entry ) {
					if ( self::is_allowed( (string) $row->user_email, array( $entry ) ) ) {
						++$counts[ $entry ];
					}
				}

				$cursor = (int) $row->ID;
			}

			if ( count( $rows ) < $chunk_size ) {
				break;
			}
		}

		return $counts;
	}

	/**
	 * Reset the cache. Test support, and after the list is edited.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$entries = null;
	}
}
