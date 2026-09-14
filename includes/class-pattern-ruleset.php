<?php
/**
 * Bad-signup pattern rules: parsing, compilation and matching.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * The bad-signup ruleset.
 *
 * Rules are regular expressions matched against one composed line per user:
 *
 *     ID,user_login,user_email
 *
 * That format is not an implementation detail - it is the coordinate system
 * every rule's anchors are written in. Change it and all 33 rules need
 * re-deriving. See dev-notes/07-email-pattern-engine.md.
 */
class Pattern_Ruleset {

	/**
	 * Delimiter for compiled patterns. No bundled rule contains it.
	 *
	 * @var string
	 */
	const DELIMITER = '~';

	/**
	 * Parsed rules, keyed by a stable hash of the pattern source.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	protected static ?array $rules = null;

	/**
	 * Patterns that failed to compile, reported rather than skipped.
	 *
	 * @var array<int,array<string,string>>
	 */
	protected static array $invalid_rules = array();

	/**
	 * Per-rule evaluation failures seen during the current run.
	 *
	 * @var array<string,int>
	 */
	protected static array $evaluation_failures = array();

	/**
	 * Every active rule, bundled defaults plus site additions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_rules(): array {
		if ( null === self::$rules ) {
			$settings       = new Settings();
			$disabled_rules = $settings->get_array( OPT_DISABLED_RULES, array() );

			$parsed = self::parse( self::read_bundled_file(), 'bundled' );
			$parsed = array_merge( $parsed, self::parse( $settings->get_string( OPT_CUSTOM_RULES ), 'site' ) );

			$enabled_rules = $settings->get_array( OPT_ENABLED_RULES, array() );

			foreach ( $parsed as $rule_key => $rule ) {
				$is_disabled = in_array( $rule_key, $disabled_rules, true );

				// A default-off rule stays off until the site explicitly enables
				// it. These are rules the corpus showed matching real people.
				$is_default_off = ! empty( $rule['default_off'] ) && ! in_array( $rule_key, $enabled_rules, true );

				if ( $is_disabled || $is_default_off ) {
					unset( $parsed[ $rule_key ] );
				}
			}

			/**
			 * Filters the compiled bad-signup ruleset.
			 *
			 * @param array<string,array<string,mixed>> $parsed Compiled rules.
			 */
			self::$rules = apply_filters( 'hwpua_pattern_rules', $parsed );
		}

		return self::$rules;
	}

	/**
	 * Read the bundled ruleset from disk.
	 *
	 * @return string
	 */
	protected static function read_bundled_file(): string {
		$bundled_path = HWPUA_DATA_DIR . 'bad-signup-rules.txt';
		$contents     = '';

		if ( is_readable( $bundled_path ) ) {
			$raw = file_get_contents( $bundled_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file we ship, on every run; WP_Filesystem would need credentials.

			if ( false === $raw ) {
				hwpua_log_error( 'The bundled bad-signup ruleset could not be read. No pattern rules are active.' );
			} else {
				$contents = $raw;
			}
		} else {
			hwpua_log_error( sprintf( 'The bundled bad-signup ruleset is missing at %s. No pattern rules are active.', $bundled_path ) );
		}

		return $contents;
	}

	/**
	 * Parse rule text into compiled rules, attaching preceding comments.
	 *
	 * The comment block above a rule carries the evidence for why it exists in
	 * that exact form - corpus sizes, measured false positives, generic patterns
	 * tried and rejected. That reasoning is worth more than the pattern, so it
	 * becomes the rule's description rather than being discarded.
	 *
	 * @param string $rule_text Raw ruleset text.
	 * @param string $source    Where the rules came from: bundled or site.
	 * @return array<string,array<string,mixed>>
	 */
	public static function parse( string $rule_text, string $source ): array {
		$parsed            = array();
		$comment_block     = array();
		$block_default_off = false;

		foreach ( preg_split( '/\R/', $rule_text ) as $raw_line ) {
			$trimmed = trim( $raw_line );

			if ( '' === $trimmed ) {
				$comment_block     = array();
				$block_default_off = false;
				continue;
			}

			if ( str_starts_with( $trimmed, '#' ) ) {
				$comment_text = trim( ltrim( $trimmed, '#' ) );

				// `#! default-off` marks the rules below it, until the next
				// blank line, as shipped-but-inactive. Used for rules the corpus
				// showed produce false positives on ordinary sites.
				if ( '! default-off' === $comment_text ) {
					$block_default_off = true;
					continue;
				}

				if ( '' !== $comment_text ) {
					$comment_block[] = $comment_text;
				}

				continue;
			}

			$compiled = self::compile( $trimmed );

			if ( '' === $compiled ) {
				self::$invalid_rules[] = array(
					'source'  => $source,
					'pattern' => $trimmed,
				);
				$comment_block         = array();
				continue;
			}

			$rule_key            = substr( hash( 'sha256', $trimmed ), 0, 16 );
			$parsed[ $rule_key ] = array(
				'key'         => $rule_key,
				'pattern'     => $trimmed,
				'compiled'    => $compiled,
				'label'       => self::build_label( $comment_block, $trimmed ),
				'description' => implode( ' ', $comment_block ),
				'source'      => $source,
				'default_off' => $block_default_off,
			);

			// The comment block is NOT cleared here. A single comment commonly
			// introduces a group of related rules - the "Known bad domains"
			// block covers half a dozen - and each of them should carry that
			// description rather than falling back to its own raw pattern. A
			// blank line ends the group.
		}

		return $parsed;
	}

	/**
	 * Compile one pattern, returning '' when it is not valid PCRE.
	 *
	 * Validation happens once at load, not per row: a malformed site-added rule
	 * must surface as a notice naming the rule, never as a warning per user and
	 * never as a rule that quietly never matches.
	 *
	 * @param string $pattern_source Raw pattern.
	 * @return string Compiled pattern, or an empty string.
	 */
	public static function compile( string $pattern_source ): string {
		$candidate = self::DELIMITER . $pattern_source . self::DELIMITER . 'i';

		// Suppress the compilation warning; the failure is reported by the caller.
		set_error_handler( static fn() => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_set_error_handler, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_error_handler -- Scoped to a single preg_match probe and restored immediately.
		$is_valid = ( false !== preg_match( $candidate, 'probe' ) );
		restore_error_handler();

		return $is_valid ? $candidate : '';
	}

	/**
	 * Derive a short label from the comment block.
	 *
	 * @param string[] $comment_block Comment lines above the rule.
	 * @param string   $pattern       The rule itself, used as a fallback.
	 * @return string
	 */
	protected static function build_label( array $comment_block, string $pattern ): string {
		$label = '';

		foreach ( $comment_block as $comment_line ) {
			// Skip decoration and commented-out variants of the rule itself.
			if ( '' !== trim( $comment_line, '#' ) && ! str_starts_with( $comment_line, '^' ) && ! str_starts_with( $comment_line, '\\' ) ) {
				$label = $comment_line;
				break;
			}
		}

		if ( '' === $label ) {
			$label = $pattern;
		}

		return rtrim( mb_substr( $label, 0, 120 ), " \t.:," );
	}

	/**
	 * Compose the line a rule is matched against.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $user_login Login name.
	 * @param string $user_email Email address.
	 * @return string
	 */
	public static function compose_line( int $user_id, string $user_login, string $user_email ): string {
		return $user_id . ',' . $user_login . ',' . $user_email;
	}

	/**
	 * Find the first rule matching a composed line.
	 *
	 * First match wins: the operator needs a reason, the cheapest reason, and
	 * the loop can stop. File order therefore matters - specific rules precede
	 * general ones so the recorded reason is the most informative.
	 *
	 * @param string $composed_line Line in ID,login,email form.
	 * @return array<string,mixed>|null The matching rule, or null.
	 */
	public static function match_line( string $composed_line ): ?array {
		$matched_rule = null;

		foreach ( self::get_rules() as $rule ) {
			$match_result = preg_match( $rule['compiled'], $composed_line );

			if ( false === $match_result ) {
				// preg_match returns false on failure, which in a boolean test is
				// indistinguishable from "no match". A rule that blows up must
				// never silently become a rule that never fires.
				$rule_key                               = $rule['key'];
				self::$evaluation_failures[ $rule_key ] = ( self::$evaluation_failures[ $rule_key ] ?? 0 ) + 1;
				continue;
			}

			if ( 1 === $match_result ) {
				$matched_rule = $rule;
				break;
			}
		}

		return $matched_rule;
	}

	/**
	 * Every rule regardless of whether it is currently enabled.
	 *
	 * The settings screen needs the full list to render a checkbox per rule;
	 * get_rules() deliberately omits the disabled ones.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function parse_all_for_display(): array {
		$settings = new Settings();

		$parsed = self::parse( self::read_bundled_file(), 'bundled' );
		$parsed = array_merge( $parsed, self::parse( $settings->get_string( OPT_CUSTOM_RULES ), 'site' ) );

		return array_values( $parsed );
	}

	/**
	 * Rules that failed to compile at load.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function get_invalid_rules(): array {
		self::get_rules();

		return self::$invalid_rules;
	}

	/**
	 * Rules that failed to evaluate, with a count of affected users.
	 *
	 * @return array<string,int>
	 */
	public static function get_evaluation_failures(): array {
		return self::$evaluation_failures;
	}

	/**
	 * Reset caches. Test support, and after the ruleset is edited.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$rules               = null;
		self::$invalid_rules       = array();
		self::$evaluation_failures = array();
	}
}
