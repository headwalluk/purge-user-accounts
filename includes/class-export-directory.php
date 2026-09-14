<?php
/**
 * Where CSV exports are written, and how long they survive.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * Resolves, provisions and purges the export directory.
 *
 * A CSV of tens of thousands of email addresses is the most concentrated
 * personal data this plugin produces. Design and threat model are in
 * dev-notes/09-safety-model.md §9.
 *
 * Defence is layered because `.htaccess` is inert on nginx, LiteSpeed and
 * Caddy: the unguessable directory name, the unguessable filenames and the
 * short lifetime are the primary protections, not the deny rules.
 */
class Export_Directory {

	/**
	 * Filename prefix. The purge only ever removes files matching this.
	 *
	 * @var string
	 */
	const FILE_PREFIX = 'hwpua-export-';

	/**
	 * Absolute path to the export directory, with no trailing slash.
	 *
	 * Prefers a location outside the web root. On the Headwall fleet each site
	 * is chrooted with a writable `private/` sibling of the document root,
	 * which is the right target and is set via the constant.
	 */
	public static function get_path(): string {
		$configured_path = '';

		if ( defined( 'HWPUA_EXPORT_DIR' ) && is_string( HWPUA_EXPORT_DIR ) ) {
			$configured_path = HWPUA_EXPORT_DIR;
		}

		/**
		 * Filters the absolute path of the export directory.
		 *
		 * @param string $configured_path Path, or empty to use the default.
		 */
		$configured_path = (string) apply_filters( 'hwpua_export_dir', $configured_path );

		if ( '' !== $configured_path ) {
			$resolved_path = untrailingslashit( $configured_path );
		} else {
			$resolved_path = untrailingslashit( WP_CONTENT_DIR ) . '/' . self::get_generated_dir_name();
		}

		return $resolved_path;
	}

	/**
	 * Whether the resolved directory sits inside the web-served tree.
	 *
	 * Drives the admin warning. Inside the web root we are relying on an
	 * unguessable name rather than on the filesystem.
	 */
	public static function is_inside_web_root(): bool {
		$export_path  = trailingslashit( wp_normalize_path( self::get_path() ) );
		$content_path = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );

		return str_starts_with( $export_path, $content_path );
	}

	/**
	 * The randomly generated directory name, created once and stored.
	 *
	 * Generated randomly and never derived from the site URL, salts or any
	 * other value an outsider could reconstruct.
	 */
	public static function get_generated_dir_name(): string {
		$settings      = new Settings();
		$stored_name   = $settings->get_string( OPT_EXPORT_DIR_NAME );
		$resolved_name = $stored_name;

		if ( ! self::is_valid_dir_name( $stored_name ) ) {
			$resolved_name = strtolower( wp_generate_password( DEF_EXPORT_DIR_NAME_LENGTH, false, false ) );
			$settings->set_string( OPT_EXPORT_DIR_NAME, $resolved_name, true );
		}

		return $resolved_name;
	}

	/**
	 * Create the directory and its guard files.
	 *
	 * @return string Empty on success, otherwise an operator-safe reason.
	 */
	public static function provision(): string {
		$export_path = self::get_path();
		$failure     = '';

		if ( ! wp_mkdir_p( $export_path ) ) {
			$failure = sprintf(
				/* translators: %s: directory path. */
				__( 'The export directory could not be created at %s. Exports will not be available until this is fixed.', 'purge-user-accounts' ),
				$export_path
			);
		} else {
			self::write_guard_files( $export_path );
		}

		return $failure;
	}

	/**
	 * Provision, and store the outcome for the admin notice.
	 *
	 * Activation frequently runs as a different OS user than PHP-FPM. On the
	 * Headwall fleet `wp-content` is owned by the site's PHP user with no group
	 * write, so `wp plugin activate` as an admin account cannot create the
	 * directory while a browser request can. Failing here is therefore normal
	 * and recoverable, not fatal - but it must never be silent.
	 *
	 * @return string Empty on success, otherwise an operator-safe reason.
	 */
	public static function record_provision_result(): string {
		$failure = self::provision();

		if ( '' === $failure ) {
			delete_option( OPT_EXPORT_DIR_ERROR );
		} else {
			update_option( OPT_EXPORT_DIR_ERROR, $failure, false );
			hwpua_log_error( $failure );
		}

		return $failure;
	}

	/**
	 * Whether the directory exists and is writable right now.
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		$export_path = self::get_path();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- A readiness probe, not a write. WP_Filesystem would need credentials we do not have on a plain admin request.
		return is_dir( $export_path ) && is_writable( $export_path );
	}

	/**
	 * Retry provisioning when it is not yet usable.
	 *
	 * @return void
	 */
	public static function ensure_ready(): void {
		if ( ! self::is_ready() ) {
			self::record_provision_result();
		}
	}

	/**
	 * Write the deny rules and the empty index file.
	 *
	 * Only meaningful inside the web root, and only on Apache - but harmless
	 * elsewhere, and the cost of being wrong about the server is high.
	 *
	 * @param string $export_path Absolute directory path.
	 * @return void
	 */
	protected static function write_guard_files( string $export_path ): void {
		$htaccess_path = $export_path . '/.htaccess';
		$index_path    = $export_path . '/index.php';

		if ( ! file_exists( $htaccess_path ) ) {
			$htaccess_body = "# Generated by Purge User Accounts.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

			self::write_file( $htaccess_path, $htaccess_body );
		}

		if ( ! file_exists( $index_path ) ) {
			self::write_file( $index_path, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Write a guard file, recording any failure rather than ignoring it.
	 *
	 * @param string $file_path Absolute path to write.
	 * @param string $file_body File contents.
	 * @return void
	 */
	protected static function write_file( string $file_path, string $file_body ): void {
		$bytes_written = @file_put_contents( $file_path, $file_body ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem needs credentials we do not have during activation; the failure is reported below.

		if ( false === $bytes_written ) {
			hwpua_log_error(
				sprintf( 'Could not write the export guard file %s. Exports may be reachable over HTTP.', $file_path )
			);
		}
	}

	/**
	 * Build an absolute path for a new export file with an unguessable name.
	 *
	 * The directory name leaks through backup tools, error logs and Referer
	 * headers more readily than people expect, so the filename carries its own
	 * entropy rather than relying on the directory for secrecy.
	 *
	 * @param int $run_id Run the export belongs to.
	 * @return string
	 */
	public static function build_file_path( int $run_id ): string {
		$random_suffix = strtolower( wp_generate_password( 16, false, false ) );
		$file_name     = sprintf( '%srun-%d-%s.csv', self::FILE_PREFIX, $run_id, $random_suffix );

		return self::get_path() . '/' . $file_name;
	}

	/**
	 * Restrict a freshly written export to its owner.
	 *
	 * Where the web server and PHP run as different users - the common setup,
	 * and the case on the Headwall fleet, where Apache workers are `www-data`
	 * and PHP-FPM is the per-site user - mode 0600 makes the file unreadable by
	 * the web server process. It cannot then be served as a static asset at all,
	 * on any server, whether or not `.htaccess` is honoured. PHP still reads it
	 * to stream the download, because PHP is the owner.
	 *
	 * Where both run as the same user this gains nothing, and costs nothing.
	 *
	 * @param string $file_path Absolute path to the written export.
	 * @return void
	 */
	public static function harden_file( string $file_path ): void {
		if ( is_file( $file_path ) && ! is_link( $file_path ) ) {
			$is_hardened = @chmod( $file_path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Narrowing permissions on a file we just wrote; WP_Filesystem needs credentials we lack on an AJAX request, and the failure is reported below rather than ignored.

			if ( ! $is_hardened ) {
				hwpua_log_error(
					sprintf( 'Could not restrict permissions on the export file %s. It may be readable by the web server.', $file_path )
				);
			}
		}
	}

	/**
	 * The most recent surviving export for a run, or '' if there is none.
	 *
	 * Exports are purged after six hours, so an empty result means the operator
	 * has not taken one recently - which is exactly what a destructive action
	 * needs to check before it starts.
	 *
	 * @param int $run_id Run identifier.
	 * @return string Absolute path, or an empty string.
	 */
	public static function find_latest_for_run( int $run_id ): string {
		$pattern         = self::get_path() . '/' . self::FILE_PREFIX . 'run-' . $run_id . '-*.csv';
		$candidate_paths = glob( $pattern );
		$candidate_paths = is_array( $candidate_paths ) ? $candidate_paths : array();

		$newest_path = '';
		$newest_time = 0;

		foreach ( $candidate_paths as $candidate_path ) {
			if ( is_link( $candidate_path ) || ! is_file( $candidate_path ) ) {
				continue;
			}

			$modified_time = filemtime( $candidate_path );

			if ( false !== $modified_time && $modified_time > $newest_time ) {
				$newest_time = $modified_time;
				$newest_path = $candidate_path;
			}
		}

		return $newest_path;
	}

	/**
	 * Delete expired export files.
	 *
	 * Removes only files this class created: inside the configured directory,
	 * matching the expected prefix, not a symlink, and never recursing.
	 *
	 * @return int Number of files removed.
	 */
	public static function purge_expired(): int {
		$export_path = self::get_path();
		$removed     = 0;

		if ( is_dir( $export_path ) ) {
			/**
			 * Filters how long an export file survives on disk, in seconds.
			 *
			 * @param int $retention_seconds Default six hours.
			 */
			$retention_seconds = (int) apply_filters( 'hwpua_export_retention_seconds', DEF_EXPORT_RETENTION_SECONDS );
			$retention_seconds = max( MINUTE_IN_SECONDS, $retention_seconds );
			$expiry_time       = time() - $retention_seconds;

			$candidate_paths = glob( $export_path . '/' . self::FILE_PREFIX . '*.csv' );
			$candidate_paths = is_array( $candidate_paths ) ? $candidate_paths : array();

			foreach ( $candidate_paths as $candidate_path ) {
				if ( is_link( $candidate_path ) || ! is_file( $candidate_path ) ) {
					continue;
				}

				$modified_time = filemtime( $candidate_path );

				if ( false === $modified_time || $modified_time > $expiry_time ) {
					continue;
				}

				if ( wp_delete_file( $candidate_path ) || ! file_exists( $candidate_path ) ) {
					++$removed;
				} else {
					hwpua_log_error( sprintf( 'Could not delete the expired export file %s.', $candidate_path ) );
				}
			}
		}

		return $removed;
	}

	/**
	 * Remove the directory, its contents and the stored name. Uninstall only.
	 */
	public static function remove(): void {
		$export_path = self::get_path();

		if ( is_dir( $export_path ) && ! self::is_inside_web_root() ) {
			// A configured path may be shared with other tooling, so remove our
			// own files but leave the directory itself in place.
			self::delete_own_files( $export_path );
		} elseif ( is_dir( $export_path ) ) {
			self::delete_own_files( $export_path );

			foreach ( array( '/.htaccess', '/index.php' ) as $guard_suffix ) {
				if ( file_exists( $export_path . $guard_suffix ) ) {
					wp_delete_file( $export_path . $guard_suffix );
				}
			}

			// Only succeeds when empty, which is the behaviour we want.
			@rmdir( $export_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Runs at uninstall where WP_Filesystem credentials are unavailable; only succeeds on an empty directory we created, and a non-empty one is left alone deliberately.
		}

		delete_option( OPT_EXPORT_DIR_NAME );
	}

	/**
	 * Delete every export file this plugin created in a directory.
	 *
	 * @param string $export_path Absolute directory path.
	 * @return void
	 */
	protected static function delete_own_files( string $export_path ): void {
		$candidate_paths = glob( $export_path . '/' . self::FILE_PREFIX . '*.csv' );
		$candidate_paths = is_array( $candidate_paths ) ? $candidate_paths : array();

		foreach ( $candidate_paths as $candidate_path ) {
			if ( is_file( $candidate_path ) && ! is_link( $candidate_path ) ) {
				wp_delete_file( $candidate_path );
			}
		}
	}

	/**
	 * Whether a stored directory name is one we would have generated.
	 *
	 * @param string $candidate_name Stored name.
	 * @return bool
	 */
	protected static function is_valid_dir_name( string $candidate_name ): bool {
		return 1 === preg_match( '/^[a-z0-9]{6,32}$/', $candidate_name );
	}
}
