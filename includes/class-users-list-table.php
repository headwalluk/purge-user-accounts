<?php
/**
 * Paged table of matched users.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders a completed run's matches.
 *
 * Reads from hwpua_run_items joined to wp_users twenty rows at a time, so the
 * result is stable: paging a live query would shift as people register.
 */
class Users_List_Table extends \WP_List_Table {

	/**
	 * Run being displayed.
	 *
	 * @var Run
	 */
	protected Run $run;

	/**
	 * Whether to show a random sample instead of ordered rows.
	 *
	 * @var bool
	 */
	protected bool $random_sample;

	/**
	 * Build the table.
	 *
	 * @param Run  $run           Completed run.
	 * @param bool $random_sample Show a random sample.
	 */
	public function __construct( Run $run, bool $random_sample = false ) {
		$this->run           = $run;
		$this->random_sample = $random_sample;

		parent::__construct(
			array(
				'singular' => 'hwpua_user',
				'plural'   => 'hwpua_users',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns, in display order.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		$columns = array(
			'user'         => __( 'User', 'purge-user-accounts' ),
			'email'        => __( 'Email', 'purge-user-accounts' ),
			'roles'        => __( 'Roles', 'purge-user-accounts' ),
			'registered'   => __( 'Registered', 'purge-user-accounts' ),
			'last_seen'    => __( 'Last seen', 'purge-user-accounts' ),
			'matched_rule' => __( 'Why matched', 'purge-user-accounts' ),
		);

		return $columns;
	}

	/**
	 * Load one page of rows.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		global $wpdb;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total_items  = $this->run->get_matched_count();

		$items_table = Schema::table( TABLE_RUN_ITEMS );
		$offset      = ( $current_page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants; every value is bound.
		if ( $this->random_sample ) {
			// Bounded by the run table, and only ever 20 rows. The first page of
			// an ID-ordered result correlates with signup date, so it looks
			// uniform whether or not the query is right - a random sample is the
			// only honest way to eyeball a large result set.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ri.user_id, ri.matched_rule, u.user_login, u.user_email, u.display_name, u.user_registered
					 FROM {$items_table} ri JOIN {$wpdb->users} u ON u.ID = ri.user_id
					 WHERE ri.run_id = %d ORDER BY RAND() LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants.
					$this->run->get_id(),
					$per_page
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ri.user_id, ri.matched_rule, u.user_login, u.user_email, u.display_name, u.user_registered
					 FROM {$items_table} ri JOIN {$wpdb->users} u ON u.ID = ri.user_id
					 WHERE ri.run_id = %d ORDER BY ri.user_id LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from our own constants.
					$this->run->get_id(),
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->items = is_array( $rows ) ? $rows : array();

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => $this->random_sample ? count( $this->items ) : $total_items,
				'per_page'    => $per_page,
				'total_pages' => $this->random_sample ? 1 : (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Shown when a run matched nobody.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No users matched this query.', 'purge-user-accounts' );
	}

	/**
	 * Render the user column.
	 *
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	public function column_user( array $item ): string {
		return sprintf(
			'<strong><a href="%s">%s</a></strong><br><span class="hwpua-muted">%s · #%d</span>',
			esc_url( get_edit_user_link( (int) $item['user_id'] ) ),
			esc_html( (string) $item['user_login'] ),
			esc_html( (string) $item['display_name'] ),
			(int) $item['user_id']
		);
	}

	/**
	 * Render the email column.
	 *
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	public function column_email( array $item ): string {
		return esc_html( (string) $item['user_email'] );
	}

	/**
	 * Render the roles column.
	 *
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	public function column_roles( array $item ): string {
		$user_object = get_userdata( (int) $item['user_id'] );
		$role_names  = ( $user_object instanceof \WP_User ) ? (array) $user_object->roles : array();

		return '' === implode( '', $role_names )
			? '<span class="hwpua-badge hwpua-badge-warn">' . esc_html__( 'no role', 'purge-user-accounts' ) . '</span>'
			: esc_html( implode( ', ', $role_names ) );
	}

	/**
	 * Render the registered column.
	 *
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	public function column_registered( array $item ): string {
		$timestamp = strtotime( (string) $item['user_registered'] . ' UTC' );

		return false === $timestamp ? '—' : esc_html( gmdate( 'j M Y', $timestamp ) );
	}

	/**
	 * Render the last-seen column, distinguishing Unknown from Never.
	 *
	 * A user who registered before the data source began recording is UNKNOWN,
	 * not Never. Reporting them as never-logged-in is how a purge removes a
	 * decade of regulars.
	 *
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	public function column_last_seen( array $item ): string {
		$described = Last_Login::describe_for_user( (int) $item['user_id'], (string) $item['user_registered'] );

		if ( 'seen' === $described['state'] ) {
			return esc_html( gmdate( 'j M Y', $described['timestamp'] ) );
		}

		if ( 'unknown' === $described['state'] ) {
			$coverage = Last_Login::get_coverage();
			$earliest = null === $coverage ? '' : $coverage->earliest_record;

			return sprintf(
				'<span class="hwpua-badge hwpua-badge-warn" title="%s">%s</span>',
				esc_attr(
					sprintf(
						/* translators: %s: earliest record date. */
						__( 'This account existed before %s, when the data source started recording. Whether they ever logged in cannot be known from it.', 'purge-user-accounts' ),
						gmdate( 'j F Y', (int) strtotime( $earliest . ' UTC' ) )
					)
				),
				esc_html( $described['label'] )
			);
		}

		$badge_class = 'never' === $described['state'] ? 'hwpua-badge hwpua-badge-never' : 'hwpua-badge';

		return sprintf( '<span class="%s">%s</span>', esc_attr( $badge_class ), esc_html( $described['label'] ) );
	}

	/**
	 * Render the reason column.
	 *
	 * How an operator audits tens of thousands of rows without reading them all:
	 * group by this, and a dozen reviewable claims replace an unreviewable list.
	 *
	 * @param array<string,mixed> $item Row data.
	 * @return string
	 */
	public function column_matched_rule( array $item ): string {
		$reason = (string) ( $item['matched_rule'] ?? '' );

		return '' === $reason
			? '<span class="hwpua-muted">' . esc_html__( 'matched the criteria', 'purge-user-accounts' ) . '</span>'
			: esc_html( $reason );
	}

	/**
	 * Fallback renderer.
	 *
	 * @param array<string,mixed> $item        Row data.
	 * @param string              $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}
