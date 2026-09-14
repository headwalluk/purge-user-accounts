<?php
/**
 * Settings tab: allowlist, ruleset, storage.
 *
 * @package PurgeUserAccounts
 *
 * @var \Purge_User_Accounts\Admin_Page $admin_page Rendering page.
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

$hwpua_settings = new Settings();
$hwpua_notices  = array();

if ( isset( $_POST[ NONCE_FIELD ] ) && check_admin_referer( NONCE_ACTION, NONCE_FIELD ) ) {
	$hwpua_raw_allowlist = isset( $_POST['hwpua_allowlist'] )
		? sanitize_textarea_field( wp_unslash( $_POST['hwpua_allowlist'] ) )
		: '';

	$hwpua_parsed = Domain_Allowlist::parse( $hwpua_raw_allowlist );

	$hwpua_settings->set_string( OPT_DOMAIN_ALLOWLIST, $hwpua_raw_allowlist );
	Domain_Allowlist::flush_cache();

	$hwpua_disabled = isset( $_POST['hwpua_disabled_rules'] ) && is_array( $_POST['hwpua_disabled_rules'] )
		? array_map( 'sanitize_key', wp_unslash( $_POST['hwpua_disabled_rules'] ) )
		: array();

	$hwpua_settings->set_array( OPT_DISABLED_RULES, $hwpua_disabled );
	Pattern_Ruleset::flush_cache();

	$hwpua_notices = $hwpua_parsed['problems'];

	printf(
		'<div class="notice notice-success"><p>%s</p></div>',
		esc_html__( 'Settings saved.', 'purge-user-accounts' )
	);

	// Showing how many of this site's own users each entry covers is a better
	// guardrail than an abstract rule: ac.uk IS a public suffix, and is exactly
	// what a university needs. An operator who types co.uk sees the number.
	if ( ! empty( $hwpua_parsed['entries'] ) ) {
		$hwpua_counts = Domain_Allowlist::count_matching_users( $hwpua_parsed['entries'] );
		$hwpua_total  = Run_History::count_users();

		echo '<div class="hwpua-panel"><h3>' . esc_html__( 'What your allowlist covers', 'purge-user-accounts' ) . '</h3>';
		echo '<table class="widefat striped"><tbody>';

		foreach ( $hwpua_counts as $hwpua_entry => $hwpua_count ) {
			printf(
				'<tr><td><code>%s</code></td><td class="hwpua-num">%s</td><td class="hwpua-num">%s</td></tr>',
				esc_html( $hwpua_entry ),
				esc_html(
					sprintf(
						/* translators: %s: user count. */
						__( '%s users', 'purge-user-accounts' ),
						number_format_i18n( $hwpua_count )
					)
				),
				esc_html( $hwpua_total > 0 ? sprintf( '%.1f%%', $hwpua_count / $hwpua_total * 100 ) : '—' )
			);
		}

		echo '</tbody></table></div>';
	}
}

foreach ( $hwpua_notices as $hwpua_problem ) {
	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $hwpua_problem ) );
}

echo '<form method="post">';
wp_nonce_field( NONCE_ACTION, NONCE_FIELD );

// --- Email domain allowlist ------------------------------------------------
printf( '<div class="hwpua-panel"><h2>%s</h2>', esc_html__( 'Email domain allowlist', 'purge-user-accounts' ) );

printf(
	'<p>%s</p>',
	esc_html__( 'Addresses on these domains are never treated as bad-signup pattern matches. This affects that criterion only — it does not spare anyone from any other filter.', 'purge-user-accounts' )
);

printf(
	'<p class="hwpua-muted">%s</p>',
	esc_html__( 'One per line. An entry covers the domain and everything beneath it, so ac.uk also covers student.gla.ac.uk. Wildcards like *.nhs.uk are accepted and mean the same thing. Lines starting with # are ignored.', 'purge-user-accounts' )
);

printf(
	'<textarea name="hwpua_allowlist" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
	esc_attr( "# Example\n*.nhs.uk\nac.uk" ),
	esc_textarea( $hwpua_settings->get_string( OPT_DOMAIN_ALLOWLIST ) )
);

echo '</div>';

// --- Bad-signup rules ------------------------------------------------------
$hwpua_all_rules = Pattern_Ruleset::parse_all_for_display();
$hwpua_disabled  = $hwpua_settings->get_array( OPT_DISABLED_RULES, array() );
$hwpua_enabled   = $hwpua_settings->get_array( OPT_ENABLED_RULES, array() );

printf( '<div class="hwpua-panel"><h2>%s</h2>', esc_html__( 'Bad-signup rules', 'purge-user-accounts' ) );

printf(
	'<p>%s</p>',
	esc_html__( 'Ticked rules are active. Rules marked "off by default" matched real people in corpus testing and stay off until a site enables them. These were derived against a private corpus of roughly 203,000 accounts across 248 sites — that evidence does not automatically transfer to your site, so review matches before acting on them.', 'purge-user-accounts' )
);

echo '<table class="widefat striped hwpua-rules"><tbody>';

foreach ( $hwpua_all_rules as $hwpua_rule ) {
	$hwpua_is_default_off = ! empty( $hwpua_rule['default_off'] );
	$hwpua_is_enabled     = ! in_array( $hwpua_rule['key'], $hwpua_disabled, true )
		&& ( ! $hwpua_is_default_off || in_array( $hwpua_rule['key'], $hwpua_enabled, true ) );

	printf(
		'<tr><td class="hwpua-rule-toggle"><input type="checkbox" name="hwpua_enabled_display" %s disabled></td>
		 <td><strong>%s</strong> <span class="hwpua-muted">%s</span><br><code class="hwpua-muted">%s</code></td></tr>',
		$hwpua_is_enabled ? 'checked' : '',
		esc_html( $hwpua_rule['label'] ),
		esc_html( $hwpua_is_default_off ? __( '(off by default)', 'purge-user-accounts' ) : '' ),
		esc_html( $hwpua_rule['pattern'] )
	);

	// Only rules the site disabled explicitly; a default-off rule is not written here,
	// or saving this form would turn its default into an override.
	if ( in_array( $hwpua_rule['key'], $hwpua_disabled, true ) ) {
		printf( '<input type="hidden" name="hwpua_disabled_rules[]" value="%s">', esc_attr( $hwpua_rule['key'] ) );
	}
}

echo '</tbody></table>';
printf( '<p class="hwpua-muted">%s</p>', esc_html__( 'Rules cannot be switched on or off from this screen yet. docs/filters.md explains how to do it with WP-CLI.', 'purge-user-accounts' ) );
echo '</div>';

// --- Storage ---------------------------------------------------------------
printf( '<div class="hwpua-panel"><h2>%s</h2>', esc_html__( 'Export storage', 'purge-user-accounts' ) );

printf(
	'<p><code>%s</code></p>',
	esc_html( Export_Directory::get_path() )
);

if ( Export_Directory::is_inside_web_root() ) {
	printf(
		'<p class="hwpua-warn">%s</p>',
		esc_html__( 'This is inside wp-content. Exports are protected by an unguessable directory name, deny rules, owner-only file permissions and a six-hour lifetime — but a path outside the web root is safer. Define HWPUA_EXPORT_DIR to move it.', 'purge-user-accounts' )
	);
} else {
	printf( '<p class="hwpua-muted">%s</p>', esc_html__( 'Outside the web root. Good.', 'purge-user-accounts' ) );
}

printf(
	'<p class="hwpua-muted">%s</p>',
	esc_html__( 'Exports are deleted from the server after six hours. The plugin keeps no durable copy — whatever you download is the only record of who was affected.', 'purge-user-accounts' )
);

echo '</div>';

printf( '<p><button type="submit" class="button button-primary">%s</button></p>', esc_html__( 'Save settings', 'purge-user-accounts' ) );
echo '</form>';
