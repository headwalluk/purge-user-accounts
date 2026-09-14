<?php
/**
 * Results tab: review a completed run.
 *
 * @package PurgeUserAccounts
 *
 * @var \Purge_User_Accounts\Admin_Page $admin_page Rendering page.
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
$hwpua_run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
$hwpua_sample = isset( $_GET['sample'] ) && '1' === sanitize_key( wp_unslash( $_GET['sample'] ) );

$hwpua_run = 0 === $hwpua_run_id ? Run_History::get_latest_complete() : Run::load( $hwpua_run_id );

if ( null === $hwpua_run ) {
	printf(
		'<div class="hwpua-panel"><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
		esc_html__( 'No completed query to show yet.', 'purge-user-accounts' ),
		esc_url( $admin_page->get_tab_url( TAB_BUILD ) ),
		esc_html__( 'Build a query', 'purge-user-accounts' )
	);
	return;
}

if ( ! $hwpua_run->is_complete() ) {
	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'This query did not finish.', 'purge-user-accounts' ),
		esc_html( '' === $hwpua_run->get_failure_reason() ? __( 'Run it again from the Build tab.', 'purge-user-accounts' ) : $hwpua_run->get_failure_reason() )
	);
	return;
}

$hwpua_age_seconds = $hwpua_run->get_age_seconds();
$hwpua_total_users = Run_History::count_users();

echo '<div class="hwpua-panel hwpua-result-summary">';

printf(
	'<h2>%s</h2>',
	esc_html(
		sprintf(
			/* translators: 1: matched count, 2: total users. */
			__( '%1$s users matched, of %2$s on this site', 'purge-user-accounts' ),
			number_format_i18n( $hwpua_run->get_matched_count() ),
			number_format_i18n( $hwpua_total_users )
		)
	)
);

printf(
	'<p class="hwpua-muted">%s</p>',
	esc_html(
		sprintf(
			/* translators: 1: run id, 2: human-readable age. */
			__( 'Query #%1$d · built %2$s ago', 'purge-user-accounts' ),
			$hwpua_run->get_id(),
			human_time_diff( time() - max( 0, $hwpua_age_seconds ) )
		)
	)
);

printf( '<p class="hwpua-criteria">%s</p>', esc_html( Run_History::describe_spec( $hwpua_run ) ) );

if ( $hwpua_run->is_stale() ) {
	printf(
		'<p class="hwpua-warn">%s</p>',
		esc_html__( 'This result is more than a day old. People register, log in and buy things in the meantime — re-run it before acting on it.', 'purge-user-accounts' )
	);
}

printf(
	'<p class="hwpua-actions">
		<a class="button" href="%s">%s</a>
		<a class="button" href="%s">%s</a>
		<a class="button" href="%s">%s</a>
	</p>',
	esc_url(
		add_query_arg(
			array(
				'page'   => ADMIN_PAGE_SLUG,
				'tab'    => TAB_RESULTS,
				'run'    => $hwpua_run->get_id(),
				'sample' => $hwpua_sample ? '0' : '1',
			),
			admin_url( 'tools.php' )
		)
	),
	$hwpua_sample ? esc_html__( 'Show all matches', 'purge-user-accounts' ) : esc_html__( 'Show 20 random matches', 'purge-user-accounts' ),
	esc_url( $admin_page->get_tab_url( TAB_BUILD ) ),
	esc_html__( 'Build another query', 'purge-user-accounts' ),
	esc_url( $admin_page->get_tab_url( TAB_HISTORY ) ),
	esc_html__( 'History', 'purge-user-accounts' )
);

echo '</div>';

// --- Export ----------------------------------------------------------------
$hwpua_existing_export = Export_Directory::find_latest_for_run( $hwpua_run->get_id() );

echo '<div class="hwpua-panel">';
printf( '<h3>%s</h3>', esc_html__( 'Export', 'purge-user-accounts' ) );

printf(
	'<p>%s</p>',
	esc_html__( 'Download the full list before acting on it. Review it in a spreadsheet, and keep it: once accounts are removed, this file is the only record of who was affected.', 'purge-user-accounts' )
);

printf(
	'<form method="post" action="%s">',
	esc_url( admin_url( 'admin-post.php' ) )
);
wp_nonce_field( NONCE_ACTION, NONCE_FIELD );
printf( '<input type="hidden" name="action" value="%s">', esc_attr( ADMIN_POST_EXPORT ) );
printf( '<input type="hidden" name="run_id" value="%d">', (int) $hwpua_run->get_id() );
printf(
	'<button type="submit" class="button button-primary">%s</button>',
	esc_html(
		sprintf(
			/* translators: %s: number of users. */
			__( 'Download CSV of %s users', 'purge-user-accounts' ),
			number_format_i18n( $hwpua_run->get_matched_count() )
		)
	)
);
echo '</form>';

printf(
	'<p class="hwpua-warn">%s</p>',
	esc_html__( 'Keep the file you download. The copy on the server is deleted after six hours — the plugin keeps no durable copy of who was in this result.', 'purge-user-accounts' )
);

if ( '' !== $hwpua_existing_export ) {
	printf(
		'<p class="hwpua-muted">%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: human-readable time difference. */
				__( 'An export of this query was taken %s ago and is still on the server.', 'purge-user-accounts' ),
				human_time_diff( (int) filemtime( $hwpua_existing_export ) )
			)
		)
	);
}

echo '</div>';

// --- Actions ---------------------------------------------------------------
echo '<div class="hwpua-panel" id="hwpua-action-panel">';
printf( '<h3>%s</h3>', esc_html__( 'Act on this result', 'purge-user-accounts' ) );

printf(
	'<p class="hwpua-muted">%s</p>',
	esc_html__( 'Least drastic first. Each action is marked reversible or permanent.', 'purge-user-accounts' )
);

echo '<ul class="hwpua-ladder">';

foreach ( Action_Registry::get_actions() as $hwpua_action ) {
	$hwpua_available = $hwpua_action->is_available();

	printf(
		'<li class="hwpua-rung%1$s" data-action-id="%2$s" data-destructiveness="%3$d">
			<label><input type="radio" name="hwpua-action" value="%2$s"%4$s> <strong>%5$s</strong></label>
			<span class="hwpua-badge%6$s">%7$s</span>
			<p class="hwpua-muted">%8$s</p>
		</li>',
		$hwpua_action->get_destructiveness() >= 50 ? ' hwpua-rung-danger' : '',
		esc_attr( $hwpua_action->get_id() ),
		(int) $hwpua_action->get_destructiveness(),
		$hwpua_available ? '' : ' disabled',
		esc_html( $hwpua_action->get_label() ),
		$hwpua_action->is_reversible() ? '' : ' hwpua-badge-never',
		$hwpua_action->is_reversible() ? esc_html__( 'reversible', 'purge-user-accounts' ) : esc_html__( 'permanent', 'purge-user-accounts' ),
		esc_html( $hwpua_available ? $hwpua_action->get_description() : $hwpua_action->get_unavailable_reason() )
	);
}

echo '</ul>';

// Delete needs an explicit decision about authored content; never a default.
printf(
	'<div class="hwpua-action-args" id="hwpua-args-delete" hidden>
		<p><strong>%s</strong></p>
		<label><input type="radio" name="hwpua-reassign" value="%d"> %s</label><br>
		<label><input type="radio" name="hwpua-reassign" value="0"> %s</label>
	</div>',
	esc_html__( 'What should happen to any content these accounts authored?', 'purge-user-accounts' ),
	(int) get_current_user_id(),
	esc_html__( 'Reassign it to me', 'purge-user-accounts' ),
	esc_html__( 'Delete it along with the accounts', 'purge-user-accounts' )
);

printf(
	'<p><label><input type="checkbox" id="hwpua-dry-run"> %s</label> <span class="hwpua-muted">%s</span></p>',
	esc_html__( 'Dry run', 'purge-user-accounts' ),
	esc_html__( '— run every check and report what would happen, without changing anything.', 'purge-user-accounts' )
);

printf(
	'<p><button type="button" class="button" id="hwpua-check">%s</button></p>',
	esc_html__( 'Check this action', 'purge-user-accounts' )
);

echo '<div id="hwpua-preflight" class="hwpua-preflight" hidden></div>';

printf(
	'<div id="hwpua-job-progress" class="hwpua-progress" hidden>
		<div class="hwpua-progress-bar"><span id="hwpua-job-fill"></span></div>
		<p id="hwpua-job-text"></p>
		<p class="hwpua-muted">%s</p>
	</div>',
	esc_html__( 'Closing this tab pauses the job. It can be resumed with WP-CLI: wp purge-users resume.', 'purge-user-accounts' )
);

echo '</div>';

// Grouping by reason turns an unreviewable list into a dozen reviewable claims.
$hwpua_reasons = Run_History::summarise_reasons( $hwpua_run );

if ( count( $hwpua_reasons ) > 1 ) {
	printf( '<div class="hwpua-panel"><h3>%s</h3><table class="widefat striped hwpua-reasons"><tbody>', esc_html__( 'Why they matched', 'purge-user-accounts' ) );

	foreach ( $hwpua_reasons as $hwpua_reason ) {
		printf(
			'<tr><td>%s</td><td class="hwpua-num">%s</td></tr>',
			esc_html( '' === $hwpua_reason['reason'] ? __( 'matched the criteria', 'purge-user-accounts' ) : $hwpua_reason['reason'] ),
			esc_html( number_format_i18n( (int) $hwpua_reason['total'] ) )
		);
	}

	echo '</tbody></table></div>';
}

if ( $hwpua_sample ) {
	printf(
		'<div class="notice notice-info inline"><p>%s</p></div>',
		esc_html__( 'A random sample. The first page of an ordered result correlates with signup date, so it looks uniform whether or not the query is right — re-roll this a few times before trusting a large result.', 'purge-user-accounts' )
	);
}

$hwpua_table = new Users_List_Table( $hwpua_run, $hwpua_sample );
$hwpua_table->prepare_items();

echo '<form method="get">';
printf( '<input type="hidden" name="page" value="%s">', esc_attr( ADMIN_PAGE_SLUG ) );
printf( '<input type="hidden" name="tab" value="%s">', esc_attr( TAB_RESULTS ) );
printf( '<input type="hidden" name="run" value="%d">', (int) $hwpua_run->get_id() );
$hwpua_table->display();
echo '</form>';
