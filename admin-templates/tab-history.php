<?php
/**
 * History tab: the audit trail.
 *
 * @package PurgeUserAccounts
 *
 * @var \Purge_User_Accounts\Admin_Page $admin_page Rendering page.
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

printf(
	'<p class="hwpua-lede">%s</p>',
	esc_html__( 'Every query this plugin has run. Results are kept for 30 days; the record of what was done is kept for twelve months.', 'purge-user-accounts' )
);

$hwpua_runs = Run_History::get_recent( 50 );

if ( empty( $hwpua_runs ) ) {
	printf( '<div class="hwpua-panel"><p>%s</p></div>', esc_html__( 'Nothing has been run yet.', 'purge-user-accounts' ) );
	return;
}

echo '<table class="widefat striped hwpua-history"><thead><tr>';

foreach ( array(
	__( 'Query', 'purge-user-accounts' ),
	__( 'Built', 'purge-user-accounts' ),
	__( 'By', 'purge-user-accounts' ),
	__( 'Status', 'purge-user-accounts' ),
	__( 'Matched', 'purge-user-accounts' ),
	__( 'Criteria', 'purge-user-accounts' ),
) as $hwpua_heading ) {
	printf( '<th>%s</th>', esc_html( $hwpua_heading ) );
}

echo '</tr></thead><tbody>';

foreach ( $hwpua_runs as $hwpua_row ) {
	$hwpua_run    = new Run( $hwpua_row );
	$hwpua_author = get_userdata( (int) $hwpua_row['created_by'] );

	printf(
		'<tr><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td><td class="hwpua-num">%s</td><td class="hwpua-criteria-cell">%s</td></tr>',
		esc_url(
			add_query_arg(
				array(
					'page' => ADMIN_PAGE_SLUG,
					'tab'  => TAB_RESULTS,
					'run'  => $hwpua_run->get_id(),
				),
				admin_url( 'tools.php' )
			)
		),
		(int) $hwpua_run->get_id(),
		esc_html( gmdate( 'j M Y H:i', (int) strtotime( (string) $hwpua_row['created_at'] . ' UTC' ) ) ),
		esc_html( $hwpua_author instanceof \WP_User ? $hwpua_author->user_login : '—' ),
		esc_html( Run_History::describe_status( $hwpua_run ) ),
		esc_html( $hwpua_run->is_complete() ? number_format_i18n( $hwpua_run->get_matched_count() ) : '—' ),
		esc_html( Run_History::describe_spec( $hwpua_run ) )
	);
}

echo '</tbody></table>';
