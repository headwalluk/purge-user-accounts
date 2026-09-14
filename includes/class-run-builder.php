<?php
/**
 * Turns a filter specification into an ordered stage plan.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Validates a filter spec and plans the stages needed to satisfy it.
 *
 * Unknown or unavailable filters are rejected, never silently dropped. A spec
 * that quietly lost its purchase filter would select and delete customers.
 */
class Run_Builder {

	/**
	 * Validate a raw specification against the registry.
	 *
	 * @param array<int,array<string,mixed>> $raw_spec Criteria as supplied.
	 * @return array<int,array<string,mixed>> Normalised criteria.
	 * @throws Run_Exception When a criterion is unknown or unavailable.
	 */
	public static function validate( array $raw_spec ): array {
		$normalised = array();

		foreach ( $raw_spec as $criterion ) {
			if ( ! is_array( $criterion ) || ! isset( $criterion['id'] ) ) {
				throw new Run_Exception(
					esc_html__( 'A selection criterion was malformed, so the query was not run.', 'purge-user-accounts' )
				);
			}

			$filter_id = (string) $criterion['id'];
			$filter    = Integration_Registry::get_filter( $filter_id );

			if ( null === $filter ) {
				throw new Run_Exception(
					sprintf(
						/* translators: %s: filter identifier. */
						esc_html__( 'The criterion "%s" is not available on this site, so the query was not run. Nothing was silently ignored.', 'purge-user-accounts' ),
						esc_html( $filter_id )
					)
				);
			}

			if ( ! $filter->is_available() ) {
				throw new Run_Exception(
					sprintf(
						/* translators: 1: filter label, 2: reason. */
						esc_html__( 'The criterion "%1$s" cannot be used: %2$s', 'purge-user-accounts' ),
						esc_html( $filter->get_label() ),
						esc_html( $filter->get_unavailable_reason() )
					)
				);
			}

			$sense = isset( $criterion['sense'] ) ? (string) $criterion['sense'] : SENSE_HAS_NOT;

			// A filter without sense carries its direction in its arguments, so it
			// is applied as written and never wrapped in NOT( ... ).
			if ( ! $filter->supports_sense() ) {
				$sense = SENSE_HAS;
			}

			if ( SENSE_HAS !== $sense && SENSE_HAS_NOT !== $sense ) {
				throw new Run_Exception(
					sprintf(
						/* translators: %s: filter label. */
						esc_html__( 'The criterion "%s" was given an unrecognised sense.', 'purge-user-accounts' ),
						esc_html( $filter->get_label() )
					)
				);
			}

			$criterion_args = isset( $criterion['args'] ) && is_array( $criterion['args'] ) ? $criterion['args'] : array();
			$argument_error = $filter->get_argument_error( $criterion_args );

			if ( '' !== $argument_error ) {
				throw new Run_Exception(
					sprintf(
						/* translators: 1: filter label, 2: what the operator must supply. */
						esc_html__( 'The criterion "%1$s" is incomplete: %2$s', 'purge-user-accounts' ),
						esc_html( $filter->get_label() ),
						esc_html( $argument_error )
					)
				);
			}

			$normalised[] = array(
				'id'    => $filter_id,
				'sense' => $sense,
				'args'  => $criterion_args,
			);
		}

		return $normalised;
	}

	/**
	 * Build the ordered stage plan for a validated specification.
	 *
	 * @param array<int,array<string,mixed>> $spec Normalised criteria.
	 * @return Stage[]
	 */
	public static function plan( array $spec ): array {
		$prepare_stages = array();
		$refine_stages  = array();
		$seen_buckets   = array();

		foreach ( $spec as $criterion ) {
			$filter = Integration_Registry::get_filter( (string) $criterion['id'] );

			if ( null === $filter ) {
				continue;
			}

			$bucket = $filter->get_scratch_bucket();

			if ( '' !== $bucket && ! isset( $seen_buckets[ $bucket ] ) ) {
				$seen_buckets[ $bucket ] = true;
				$prepare_stages[]        = new Stage(
					STAGE_PREPARE,
					sprintf(
						/* translators: %s: filter label. */
						__( 'Preparing data for "%s"', 'purge-user-accounts' ),
						$filter->get_label()
					),
					$filter->get_id(),
					$criterion
				);
			}

			if ( Filter::KIND_REFINE === $filter->get_kind() ) {
				$refine_stages[] = new Stage(
					STAGE_REFINE,
					sprintf(
						/* translators: %s: filter label. */
						__( 'Applying "%s"', 'purge-user-accounts' ),
						$filter->get_label()
					),
					$filter->get_id(),
					$criterion
				);
			}
		}

		$seed_stage  = new Stage( STAGE_SEED, __( 'Selecting candidates', 'purge-user-accounts' ) );
		$final_stage = new Stage( STAGE_FINALISE, __( 'Finishing up', 'purge-user-accounts' ) );

		return array_merge( $prepare_stages, array( $seed_stage ), $refine_stages, array( $final_stage ) );
	}

	/**
	 * Warn about criterion combinations that cannot return anything.
	 *
	 * Found during M7: the safe login variant treats everyone registered before
	 * the data source began as unknown, so it can never return them. Combined
	 * with "registered more than a year ago" on a site whose login data is
	 * newer than that, the two sets cannot overlap and the query returns zero.
	 *
	 * Both criteria are individually reasonable, so an operator gets an empty
	 * result with no idea why. Saying so is cheap; leaving them to guess is not.
	 *
	 * @param array<int,array<string,mixed>> $spec Normalised criteria.
	 * @return string[] Operator-facing warnings.
	 */
	public static function detect_impossible_combinations( array $spec ): array {
		$warnings = array();

		$safe_login_used   = false;
		$registered_cutoff = '';

		foreach ( $spec as $criterion ) {
			$criterion_id = (string) $criterion['id'];
			$args         = (array) $criterion['args'];

			$is_login_criterion = in_array( $criterion_id, array( 'wp-core.login-record', 'wp-core.active-since' ), true );
			$includes_unknown   = isset( $args['include_unknown'] ) && (bool) $args['include_unknown'];

			if ( $is_login_criterion && SENSE_HAS_NOT === $criterion['sense'] && ! $includes_unknown ) {
				$safe_login_used = true;
			}

			if ( 'wp-core.registered' === $criterion_id && 'after' !== ( $args['direction'] ?? 'before' ) ) {
				if ( isset( $args['days_ago'] ) && is_numeric( $args['days_ago'] ) ) {
					$registered_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $args['days_ago'] * DAY_IN_SECONDS ) );
				} elseif ( isset( $args['date'] ) && is_string( $args['date'] ) ) {
					$parsed            = strtotime( $args['date'] . ' UTC' );
					$registered_cutoff = false === $parsed ? '' : gmdate( 'Y-m-d H:i:s', $parsed );
				}
			}
		}

		if ( $safe_login_used && '' !== $registered_cutoff ) {
			$coverage = Last_Login::get_coverage();
			$earliest = null === $coverage ? '' : $coverage->earliest_record;

			if ( '' !== $earliest && $earliest > $registered_cutoff ) {
				$warnings[] = sprintf(
					/* translators: 1: earliest record date, 2: registration cutoff date. */
					__( 'These criteria cannot overlap, so this query will match nobody. Your login data only goes back to %1$s, and every account registered before then is treated as "unknown" rather than "never logged in". But you have also asked for accounts registered before %2$s — which is all of them. Either widen the registration date, or tick "include users whose login history is unknown" and review the results carefully.', 'purge-user-accounts' ),
					esc_html( gmdate( 'j F Y', (int) strtotime( $earliest . ' UTC' ) ) ),
					esc_html( gmdate( 'j F Y', (int) strtotime( $registered_cutoff . ' UTC' ) ) )
				);
			}
		}

		return $warnings;
	}

	/**
	 * The SQL-capable criteria, which collapse into the seed statement.
	 *
	 * @param array<int,array<string,mixed>> $spec Normalised criteria.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_sql_criteria( array $spec ): array {
		$sql_criteria = array();

		foreach ( $spec as $criterion ) {
			$filter = Integration_Registry::get_filter( (string) $criterion['id'] );

			if ( null !== $filter && Filter::KIND_SQL === $filter->get_kind() ) {
				$sql_criteria[] = $criterion;
			}
		}

		return $sql_criteria;
	}
}
