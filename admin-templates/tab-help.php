<?php
/**
 * Help tab: a safe order of work, and links to the full documentation.
 *
 * @package PurgeUserAccounts
 *
 * @var \Purge_User_Accounts\Admin_Page $admin_page Rendering page.
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

printf(
	'<p class="hwpua-lede">%s</p>',
	esc_html__( 'How to use this tool without removing real people, and where the full documentation lives.', 'purge-user-accounts' )
);

$hwpua_help_sections = array(
	array(
		'heading' => __( 'A safe order of work', 'purge-user-accounts' ),
		'steps'   => array(
			__( 'Build a query from more than one criterion. A bad-signup pattern plus "no orders" plus "no content" is a far stronger case than any one of them alone.', 'purge-user-accounts' ),
			__( 'Read the Results. The "why matched" column names the rule behind each account, and the random sample is there to be read, not skimmed.', 'purge-user-accounts' ),
			__( 'Download the CSV. It is required before stripping roles, scrambling passwords or deleting, it is removed from the server after six hours, and the plugin keeps no copy - keep your own.', 'purge-user-accounts' ),
			__( 'Block sign-in first. It refuses sign-in and password resets, ends sessions and revokes application passwords, and it can be reversed.', 'purge-user-accounts' ),
			__( 'Delete later, once nothing has broken. Decide who inherits any content those accounts authored before you start.', 'purge-user-accounts' ),
		),
	),
);

foreach ( $hwpua_help_sections as $hwpua_section ) {
	echo '<div class="hwpua-panel">';
	printf( '<h2>%s</h2>', esc_html( $hwpua_section['heading'] ) );
	echo '<ol>';

	foreach ( $hwpua_section['steps'] as $hwpua_step ) {
		printf( '<li>%s</li>', esc_html( $hwpua_step ) );
	}

	echo '</ol></div>';
}

$hwpua_help_notes = array(
	__( 'Last-login data', 'purge-user-accounts' )       => __( 'A login source only knows about sign-ins since it started recording. An account older than that is Unknown, not Never, and the safe variant of each login criterion leaves Unknown accounts out. Check the coverage panel on the Build tab before relying on login history.', 'purge-user-accounts' ),
	__( 'Bad-signup rules', 'purge-user-accounts' )      => __( 'The bundled rules are a record of signup campaigns already seen, not a general theory of bot accounts. A site facing an unfamiliar campaign will need its own rules. Rules known to catch real people ship switched off, and the email domain allowlist on the Settings tab exempts domains you trust.', 'purge-user-accounts' ),
	__( 'What cannot be undone', 'purge-user-accounts' ) => __( 'Deleting accounts, scrambling passwords and revoking application passwords are permanent. Blocking sign-in and stripping roles are reversed with "Unblock sign-in" and "Restore roles".', 'purge-user-accounts' ),
	__( 'Administrators and yourself', 'purge-user-accounts' ) => __( 'Your own account and the last administrator are never acted on, and other accounts that can manage users are skipped. These checks are repeated when an action runs, not only when the query is built.', 'purge-user-accounts' ),
);

foreach ( $hwpua_help_notes as $hwpua_note_heading => $hwpua_note_text ) {
	printf(
		'<div class="hwpua-panel"><h2>%s</h2><p>%s</p></div>',
		esc_html( $hwpua_note_heading ),
		esc_html( $hwpua_note_text )
	);
}

$hwpua_doc_links = array(
	'operators-guide.md' => __( "Operator's guide", 'purge-user-accounts' ),
	'filters.md'         => __( 'Criteria and their exact meaning', 'purge-user-accounts' ),
	'actions.md'         => __( 'Actions, guards and reversibility', 'purge-user-accounts' ),
	'cli.md'             => __( 'WP-CLI commands and presets', 'purge-user-accounts' ),
);

echo '<div class="hwpua-panel">';
printf( '<h2>%s</h2><ul>', esc_html__( 'Documentation', 'purge-user-accounts' ) );

foreach ( $hwpua_doc_links as $hwpua_doc_file => $hwpua_doc_label ) {
	printf(
		'<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
		esc_url( DOCS_URL . $hwpua_doc_file ),
		esc_html( $hwpua_doc_label )
	);
}

echo '</ul></div>';
