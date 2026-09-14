<?php
/**
 * Build tab: compose a query.
 *
 * @package PurgeUserAccounts
 *
 * @var \Purge_User_Accounts\Admin_Page $admin_page Rendering page.
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

$hwpua_groups = array(
	'roles'        => __( 'Roles', 'purge-user-accounts' ),
	'content'      => __( 'Content and comments', 'purge-user-accounts' ),
	'purchases'    => __( 'Purchases', 'purge-user-accounts' ),
	'login'        => __( 'Login activity', 'purge-user-accounts' ),
	'patterns'     => __( 'Email and username patterns', 'purge-user-accounts' ),
	'registration' => __( 'Registration date', 'purge-user-accounts' ),
	'general'      => __( 'Other', 'purge-user-accounts' ),
);

$hwpua_filters_by_group = array();

foreach ( Integration_Registry::get_filters() as $hwpua_filter ) {
	$hwpua_filters_by_group[ $hwpua_filter->get_group() ][] = $hwpua_filter;
}

// Unavailable integrations are shown, not hidden: an operator wondering why
// they cannot filter on purchases should read "WooCommerce is not active".
$hwpua_unavailable = array();

foreach ( Integration_Registry::get_integrations() as $hwpua_integration ) {
	if ( ! $hwpua_integration->is_available() ) {
		$hwpua_unavailable[] = $hwpua_integration;
	}
}

printf(
	'<p class="hwpua-lede">%s</p>',
	esc_html__( 'Tick criteria to build a query. Criteria combine with AND — and combining them is the point: a subscriber is unremarkable, but a subscriber with no content, no orders, no login record and a throwaway address is a conclusion.', 'purge-user-accounts' )
);

hwpua_render_preset_panel();

echo '<form id="hwpua-build-form" class="hwpua-build">';

foreach ( $hwpua_groups as $hwpua_group_key => $hwpua_group_label ) {
	if ( empty( $hwpua_filters_by_group[ $hwpua_group_key ] ) ) {
		continue;
	}

	printf( '<fieldset class="hwpua-group"><legend>%s</legend>', esc_html( $hwpua_group_label ) );

	if ( 'login' === $hwpua_group_key ) {
		hwpua_render_login_panel();
	}

	foreach ( $hwpua_filters_by_group[ $hwpua_group_key ] as $hwpua_filter ) {
		hwpua_render_filter_row( $hwpua_filter );
	}

	printf( '</fieldset>' );
}

foreach ( $hwpua_unavailable as $hwpua_integration ) {
	printf(
		'<fieldset class="hwpua-group hwpua-group-disabled"><legend>%s</legend><p class="hwpua-muted">%s</p></fieldset>',
		esc_html( $hwpua_integration->get_label() ),
		esc_html( $hwpua_integration->get_unavailable_reason() )
	);
}

echo '<div id="hwpua-warnings" class="hwpua-warnings" hidden></div>';

printf(
	'<p class="hwpua-actions">
		<button type="button" class="button" id="hwpua-estimate">%s</button>
		<button type="button" class="button button-primary" id="hwpua-run">%s</button>
		<span id="hwpua-estimate-result" class="hwpua-estimate-result"></span>
	</p>',
	esc_html__( 'Estimate matches', 'purge-user-accounts' ),
	esc_html__( 'Run query', 'purge-user-accounts' )
);

printf(
	'<div id="hwpua-progress" class="hwpua-progress" hidden>
		<div class="hwpua-progress-bar"><span id="hwpua-progress-fill"></span></div>
		<p id="hwpua-progress-text"></p>
		<p class="hwpua-muted">%s</p>
	</div>',
	esc_html__( 'Safe to close this tab. Progress is saved and the query can be resumed from the History tab.', 'purge-user-accounts' )
);

echo '</form>';

/**
 * Render the saved-query panel.
 *
 * Loading a saved query only ticks the boxes - it does not run anything. The
 * operator sees exactly what they are about to run, in the same controls they
 * would have used by hand, which is what makes a preset from another site safe
 * to act on.
 *
 * @return void
 */
function hwpua_render_preset_panel(): void {
	$presets = Preset::get_all();

	echo '<div class="hwpua-panel hwpua-presets" id="hwpua-preset-panel">';

	printf( '<p class="hwpua-presets-row"><strong>%s</strong>', esc_html__( 'Saved queries', 'purge-user-accounts' ) );

	echo '<select id="hwpua-preset-select">';
	printf( '<option value="">%s</option>', esc_html__( '— choose —', 'purge-user-accounts' ) );

	foreach ( $presets as $preset_slug => $preset ) {
		printf( '<option value="%s">%s</option>', esc_attr( $preset_slug ), esc_html( (string) ( $preset['label'] ?? $preset_slug ) ) );
	}

	echo '</select>';

	printf(
		'<button type="button" class="button" id="hwpua-preset-load">%s</button>
		 <button type="button" class="button" id="hwpua-preset-export">%s</button>
		 <button type="button" class="button-link hwpua-danger-link" id="hwpua-preset-delete">%s</button>',
		esc_html__( 'Load', 'purge-user-accounts' ),
		esc_html__( 'Export', 'purge-user-accounts' ),
		esc_html__( 'Forget', 'purge-user-accounts' )
	);

	echo '</p>';

	printf(
		'<p class="hwpua-presets-row">
			<label for="hwpua-preset-name">%s</label>
			<input type="text" id="hwpua-preset-name" class="regular-text" placeholder="%s">
			<button type="button" class="button" id="hwpua-preset-save">%s</button>
			<button type="button" class="button" id="hwpua-preset-import">%s</button>
		</p>',
		esc_html__( 'Save the ticked criteria as:', 'purge-user-accounts' ),
		esc_attr__( 'Dormant subscribers with no orders', 'purge-user-accounts' ),
		esc_html__( 'Save', 'purge-user-accounts' ),
		esc_html__( 'Import…', 'purge-user-accounts' )
	);

	printf( '<p id="hwpua-preset-message" class="hwpua-muted" hidden></p>' );
	printf(
		'<p class="hwpua-muted">%s</p>',
		esc_html__( 'A saved query stores criteria, not results. Loading one ticks the boxes below so you can check and adjust it before running.', 'purge-user-accounts' )
	);

	echo '</div>';
}

/**
 * Render the last-login data-quality panel.
 *
 * Neither login criterion may be offered as a bare tick. The operator has to be
 * able to see which source is in use, when it started, and how many users it
 * cannot speak for.
 *
 * @return void
 */
function hwpua_render_login_panel(): void {
	$source = Last_Login::get_active_source();

	if ( null === $source ) {
		printf( '<div class="hwpua-panel hwpua-panel-info"><p>%s</p></div>', esc_html( Last_Login::get_unavailable_reason() ) );
		return;
	}

	$coverage = Last_Login::get_coverage();

	if ( null === $coverage ) {
		return;
	}

	echo '<div class="hwpua-panel hwpua-panel-info">';

	printf(
		'<p><strong>%s</strong> %s</p>',
		esc_html__( 'Data source:', 'purge-user-accounts' ),
		esc_html( $source->label )
	);

	if ( ! $source->means_login() ) {
		printf(
			'<p class="hwpua-warn">%s</p>',
			esc_html__( 'This source records ACTIVITY, not logins. Someone who stays signed in and browses counts as active without ever logging in again.', 'purge-user-accounts' )
		);
	}

	printf(
		'<table class="hwpua-coverage"><tbody>
			<tr><th>%s</th><td>%s</td></tr>
			<tr><th>%s</th><td>%s</td></tr>
			<tr><th>%s</th><td>%s</td></tr>
		</tbody></table>',
		esc_html__( 'Earliest record', 'purge-user-accounts' ),
		'' === $coverage->earliest_record
			? esc_html__( 'nothing recorded yet', 'purge-user-accounts' )
			: esc_html( gmdate( 'j F Y', (int) strtotime( $coverage->earliest_record . ' UTC' ) ) ),
		esc_html__( 'Users with a record', 'purge-user-accounts' ),
		esc_html( number_format_i18n( $coverage->users_with_record ) ),
		esc_html__( 'Users with no record', 'purge-user-accounts' ),
		esc_html( number_format_i18n( $coverage->users_without_record ) )
	);

	if ( $coverage->has_unknown_cohort() ) {
		printf(
			'<p class="hwpua-warn"><strong>%s</strong> %s</p>',
			esc_html(
				sprintf(
					/* translators: %s: number of users. */
					__( '%s of those are Unknown, not Never.', 'purge-user-accounts' ),
					number_format_i18n( $coverage->users_registered_before_earliest )
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: date. */
					__( 'They registered before %s, when this source started recording, so it cannot say whether they ever logged in. Only the remainder can be called "never logged in" with confidence.', 'purge-user-accounts' ),
					gmdate( 'j F Y', (int) strtotime( $coverage->earliest_record . ' UTC' ) )
				)
			)
		);
	}

	echo '</div>';
}

/**
 * Render one criterion row.
 *
 * @param Filter $filter Criterion to render.
 * @return void
 */
function hwpua_render_filter_row( Filter $filter ): void {
	$filter_id    = $filter->get_id();
	$is_available = $filter->is_available();
	$control_id   = 'hwpua-f-' . sanitize_html_class( $filter_id );

	printf(
		'<div class="hwpua-filter%s" data-filter-id="%s">',
		$is_available ? '' : ' hwpua-filter-disabled',
		esc_attr( $filter_id )
	);

	printf(
		'<label for="%s"><input type="checkbox" id="%s" class="hwpua-filter-toggle" value="%s"%s> %s</label>',
		esc_attr( $control_id ),
		esc_attr( $control_id ),
		esc_attr( $filter_id ),
		$is_available ? '' : ' disabled',
		esc_html( $filter->get_label() )
	);

	if ( ! $is_available ) {
		printf( '<p class="hwpua-muted">%s</p>', esc_html( $filter->get_unavailable_reason() ) );
		echo '</div>';
		return;
	}

	echo '<div class="hwpua-filter-options">';

	if ( $filter->supports_sense() ) {
		printf(
			'<label><input type="radio" name="sense-%1$s" class="hwpua-sense" value="%2$s" checked> %3$s</label>
			 <label><input type="radio" name="sense-%1$s" class="hwpua-sense" value="%4$s"> %5$s</label>',
			esc_attr( sanitize_html_class( $filter_id ) ),
			esc_attr( SENSE_HAS_NOT ),
			esc_html__( 'has none', 'purge-user-accounts' ),
			esc_attr( SENSE_HAS ),
			esc_html__( 'has one or more', 'purge-user-accounts' )
		);
	}

	hwpua_render_filter_arguments( $filter );

	echo '</div></div>';
}

/**
 * Render the arguments a particular criterion needs.
 *
 * @param Filter $filter Criterion to render arguments for.
 * @return void
 */
function hwpua_render_filter_arguments( Filter $filter ): void {
	switch ( $filter->get_id() ) {
		case 'wp-core.role':
			echo '<span class="hwpua-arg">';
			foreach ( wp_roles()->get_names() as $hwpua_role_slug => $hwpua_role_label ) {
				printf(
					'<label class="hwpua-inline"><input type="checkbox" class="hwpua-arg-roles" value="%s"> %s</label>',
					esc_attr( $hwpua_role_slug ),
					esc_html( translate_user_role( $hwpua_role_label ) )
				);
			}
			echo '</span>';
			break;

		case 'wp-core.registered':
			printf(
				'<span class="hwpua-arg"><label>%s <input type="number" class="hwpua-arg-days" min="0" value="365" size="5"> %s</label></span>',
				esc_html__( 'more than', 'purge-user-accounts' ),
				esc_html__( 'days ago', 'purge-user-accounts' )
			);
			break;

		case 'wp-core.active-since':
			printf(
				'<span class="hwpua-arg"><label>%s <input type="number" class="hwpua-arg-days" min="1" value="365" size="5"> %s</label></span>',
				esc_html__( 'within the last', 'purge-user-accounts' ),
				esc_html__( 'days', 'purge-user-accounts' )
			);
			hwpua_render_unknown_opt_in();
			break;

		case 'wp-core.login-record':
			hwpua_render_unknown_opt_in();
			break;

		case 'wp-core.bad-signup-pattern':
			printf(
				'<span class="hwpua-arg"><label><input type="checkbox" class="hwpua-arg-allowlist" checked> %s</label> <span class="hwpua-muted">(%s)</span></span>',
				esc_html__( 'Ignore allowlisted domains', 'purge-user-accounts' ),
				esc_html(
					sprintf(
						/* translators: 1: rule count, 2: allowlist entry count. */
						__( '%1$d rules active, %2$d domains allowlisted', 'purge-user-accounts' ),
						count( Pattern_Ruleset::get_rules() ),
						count( Domain_Allowlist::get_entries() )
					)
				)
			);
			break;

		case 'woocommerce.orders':
			printf(
				'<span class="hwpua-arg"><label><input type="checkbox" class="hwpua-arg-guest" checked> %s</label></span>',
				esc_html__( 'Also count guest orders matched by email address', 'purge-user-accounts' )
			);
			printf( '<p class="hwpua-muted">%s</p>', esc_html__( 'Leave this ticked. A customer who ordered as a guest before registering is linked to that order only by their email address.', 'purge-user-accounts' ) );
			break;

		default:
			// Criteria with no arguments need no controls.
			break;
	}
}

/**
 * Render the opt-in that widens a login criterion to the unknown cohort.
 *
 * @return void
 */
function hwpua_render_unknown_opt_in(): void {
	printf(
		'<span class="hwpua-arg"><label class="hwpua-danger-opt"><input type="checkbox" class="hwpua-arg-unknown"> %s</label></span>',
		esc_html__( 'Also include users whose login history is unknown (riskier)', 'purge-user-accounts' )
	);
}
