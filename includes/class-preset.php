<?php
/**
 * Named, reusable filter specifications.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Saved queries.
 *
 * Presets are the CLI's interface. Designing flags for arbitrary filter
 * combination is a large problem for little gain; the UI builds the query and
 * the command line runs it by name. A query defined once then reaches every
 * site on a fleet through a shell loop.
 *
 * See dev-notes/10-cli-and-presets.md.
 */
class Preset {

	/**
	 * Every stored preset, keyed by slug.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_all(): array {
		$settings = new Settings();
		$stored   = $settings->get_array( OPT_PRESETS, array() );
		$presets  = array();

		foreach ( $stored as $slug => $preset ) {
			if ( is_array( $preset ) && isset( $preset['filter_spec'] ) ) {
				$presets[ (string) $slug ] = $preset;
			}
		}

		return $presets;
	}

	/**
	 * One preset by slug, or null.
	 *
	 * @param string $slug Preset identifier.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$presets = self::get_all();

		return $presets[ $slug ] ?? null;
	}

	/**
	 * Store a preset.
	 *
	 * @param string                         $label       Human-readable name.
	 * @param array<int,array<string,mixed>> $filter_spec Validated criteria.
	 * @param string                         $description Optional note.
	 * @return string The slug it was stored under.
	 * @throws Run_Exception When the specification is unusable.
	 */
	public static function save( string $label, array $filter_spec, string $description = '' ): string {
		$label = trim( $label );

		if ( '' === $label ) {
			throw new Run_Exception( esc_html__( 'Give the preset a name.', 'purge-user-accounts' ) );
		}

		// Validated before storing: a preset that cannot run is worse than no
		// preset, because it will be discovered at the moment someone needs it.
		$validated = Run_Builder::validate( $filter_spec );

		// An empty specification selects every account. Refused here rather than
		// only in the Build tab, because a preset is a named, shareable thing:
		// somebody would load it on another site and trust the name.
		if ( empty( $validated ) ) {
			throw new Run_Exception( esc_html__( 'A saved query needs at least one criterion. An empty one would select every account on the site.', 'purge-user-accounts' ) );
		}

		$slug     = sanitize_title( $label );
		$settings = new Settings();
		$presets  = self::get_all();

		$presets[ $slug ] = array(
			'label'       => $label,
			'description' => $description,
			'filter_spec' => $validated,
			'created_at'  => hwpua_now(),
			'created_by'  => get_current_user_id(),
		);

		$settings->set_array( OPT_PRESETS, $presets );

		return $slug;
	}

	/**
	 * Remove a preset.
	 *
	 * @param string $slug Preset identifier.
	 * @return bool Whether it existed.
	 */
	public static function delete( string $slug ): bool {
		$presets = self::get_all();
		$existed = isset( $presets[ $slug ] );

		unset( $presets[ $slug ] );

		$settings = new Settings();
		$settings->set_array( OPT_PRESETS, $presets );

		return $existed;
	}

	/**
	 * Which integrations a preset needs.
	 *
	 * Derived from the namespaced filter ids, so nothing extra has to be stored.
	 *
	 * @param array<string,mixed> $preset Stored preset.
	 * @return string[]
	 */
	public static function get_required_integrations( array $preset ): array {
		$required = array();

		foreach ( (array) ( $preset['filter_spec'] ?? array() ) as $criterion ) {
			if ( ! is_array( $criterion ) || ! isset( $criterion['id'] ) ) {
				continue;
			}

			$parts = explode( '.', (string) $criterion['id'], 2 );

			if ( '' !== $parts[0] ) {
				$required[] = $parts[0];
			}
		}

		return array_values( array_unique( $required ) );
	}

	/**
	 * Check a preset against this site, reporting anything that will not apply.
	 *
	 * A criterion that cannot run is a LOUD condition, never a silent one. A
	 * preset that quietly dropped its purchase filter would select and delete
	 * customers.
	 *
	 * @param array<string,mixed> $preset Preset to check.
	 * @return array{ok:bool,lines:array<int,array<string,string>>}
	 */
	public static function validate_against_site( array $preset ): array {
		$lines = array();
		$is_ok = true;

		foreach ( self::get_required_integrations( $preset ) as $integration_id ) {
			$integration = Integration_Registry::get_integration( $integration_id );

			if ( null === $integration ) {
				$is_ok   = false;
				$lines[] = array(
					'level' => 'error',
					'text'  => sprintf(
						/* translators: %s: integration identifier. */
						__( 'Integration "%s" is not installed here.', 'purge-user-accounts' ),
						$integration_id
					),
				);
				continue;
			}

			if ( ! $integration->is_available() ) {
				$is_ok   = false;
				$lines[] = array(
					'level' => 'error',
					'text'  => sprintf(
						/* translators: 1: integration label, 2: reason. */
						__( '%1$s — %2$s', 'purge-user-accounts' ),
						$integration->get_label(),
						$integration->get_unavailable_reason()
					),
				);
				continue;
			}

			$lines[] = array(
				'level' => 'ok',
				'text'  => $integration->get_label(),
			);
		}

		foreach ( (array) ( $preset['filter_spec'] ?? array() ) as $criterion ) {
			if ( ! is_array( $criterion ) || ! isset( $criterion['id'] ) ) {
				continue;
			}

			$filter_id = (string) $criterion['id'];
			$filter    = Integration_Registry::get_filter( $filter_id );

			if ( null === $filter || ! $filter->is_available() ) {
				$is_ok   = false;
				$lines[] = array(
					'level' => 'error',
					'text'  => sprintf(
						/* translators: %s: criterion identifier. */
						__( 'Criterion "%s" cannot be used on this site.', 'purge-user-accounts' ),
						$filter_id
					),
				);
			}
		}

		// Role slugs a preset names may simply not exist here.
		foreach ( (array) ( $preset['filter_spec'] ?? array() ) as $criterion ) {
			if ( ! is_array( $criterion ) || 'wp-core.role' !== ( $criterion['id'] ?? '' ) ) {
				continue;
			}

			$known_roles = array_keys( wp_roles()->get_names() );

			foreach ( (array) ( $criterion['args']['roles'] ?? array() ) as $role_slug ) {
				if ( ! in_array( (string) $role_slug, $known_roles, true ) ) {
					$lines[] = array(
						'level' => 'warn',
						'text'  => sprintf(
							/* translators: %s: role slug. */
							__( 'Role "%s" does not exist here, so that part of the query will match nothing.', 'purge-user-accounts' ),
							(string) $role_slug
						),
					);
				}
			}
		}

		return array(
			'ok'    => $is_ok,
			'lines' => $lines,
		);
	}

	/**
	 * Serialise a preset for transfer to another site.
	 *
	 * @param string $slug Preset identifier.
	 * @return string JSON, or an empty string when unknown.
	 */
	public static function export( string $slug ): string {
		$preset = self::get( $slug );

		if ( null === $preset ) {
			return '';
		}

		$payload = array(
			'hwpua_preset' => 1,
			'slug'         => $slug,
			'label'        => $preset['label'] ?? $slug,
			'description'  => $preset['description'] ?? '',
			'filter_spec'  => $preset['filter_spec'] ?? array(),
		);

		$encoded = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Read an exported preset without storing it.
	 *
	 * @param string $json Exported JSON.
	 * @return array<string,mixed>
	 * @throws Run_Exception When the payload is not a preset.
	 */
	public static function parse_export( string $json ): array {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) || empty( $decoded['hwpua_preset'] ) || ! isset( $decoded['filter_spec'] ) ) {
			throw new Run_Exception( esc_html__( 'That does not look like an exported preset.', 'purge-user-accounts' ) );
		}

		return array(
			'label'       => (string) ( $decoded['label'] ?? $decoded['slug'] ?? 'Imported preset' ),
			'description' => (string) ( $decoded['description'] ?? '' ),
			'filter_spec' => is_array( $decoded['filter_spec'] ) ? $decoded['filter_spec'] : array(),
		);
	}
}
