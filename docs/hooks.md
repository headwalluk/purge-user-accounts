# Hooks and constants

Every hook the plugin registers against, every hook it emits, and the constants a site can
define. On a multisite network only the WooCommerce compatibility declaration and the notice
explaining why the plugin is inactive are registered.

## Hooks emitted

### `hwpua_register_integrations` (filter)

The extension point. See [integrations.md](integrations.md).

| Parameter | Type | Description |
|---|---|---|
| `$integrations` | `Integration[]` | Integrations registered so far |

Return the array with yours added. Applied once per request, the first time integrations,
filters, actions or login sources are needed.

### `hwpua_guarded_user_ids` (filter)

Adds users who are excluded from every run at selection and skipped by every job.

| Parameter | Type | Description |
|---|---|---|
| `$excluded_ids` | `int[]` | Guarded IDs; contains the current user when there is one |

Values are cast with `absint()` and zero is removed. The current user is added back after
the filter runs, so the filter can add protection but cannot remove the current user. The
last-administrator and privileged-role guards are applied separately and cannot be
affected by this filter.

```php
add_filter(
	'hwpua_guarded_user_ids',
	static function ( array $excluded_ids ): array {
		$excluded_ids[] = 42;

		return $excluded_ids;
	}
);
```

Users protected this way are recorded on a job with the reason "This user can manage other
users."

### `hwpua_pattern_rules` (filter)

The compiled bad-signup ruleset, after disabled and default-off rules have been removed.

| Parameter | Type | Description |
|---|---|---|
| `$rules` | `array<string,array>` | Rule key ⇒ rule |

Each rule is an array with `key`, `pattern`, `compiled` (the full PCRE with delimiters and
flags), `label`, `description`, `source` (`bundled` or `site`) and `default_off`. Matching
uses `compiled` and records `label`; rules are tried in array order. Applied once per
request.

### `hwpua_csv_columns` (filter)

Adds user meta columns to the CSV export, after the standard columns.

| Parameter | Type | Description |
|---|---|---|
| `$meta_keys` | `string[]` | User meta keys; empty by default |

The column heading is the meta key. A scalar value is written as-is; anything else as JSON.
Meta is cached per chunk of 2,000 users.

```php
add_filter(
	'hwpua_csv_columns',
	static function ( array $meta_keys ): array {
		$meta_keys[] = 'billing_phone';

		return $meta_keys;
	}
);
```

### `hwpua_seed_chunk_size` (filter)

| Parameter | Type | Default |
|---|---|---|
| `$chunk_size` | `int` | `25000` user IDs per seed step |

Values below 10 are raised to 10. Lower it if a seed step with expensive predicates is
close to a request timeout.

### `hwpua_run_stale_seconds` (filter)

| Parameter | Type | Default |
|---|---|---|
| `$stale_seconds` | `int` | `86400` (one day) |

How long after completion a run can still be acted on.

### `hwpua_minimum_criteria` (filter)

| Parameter | Type | Default |
|---|---|---|
| `$minimum` | `int` | `2` |

The pre-flight warns when an action of destructiveness 50 or more is applied to a run with
fewer criteria than this.

### `hwpua_proportion_warning` (filter)

| Parameter | Type | Default |
|---|---|---|
| `$threshold` | `float` | `0.5` |

The pre-flight warns when an action of destructiveness 50 or more is applied to a run whose
match count is at least this share of all users.

### `hwpua_export_dir` (filter)

| Parameter | Type | Description |
|---|---|---|
| `$configured_path` | `string` | The `HWPUA_EXPORT_DIR` value, or empty |

Return an absolute path to use for export files, or empty for the generated directory in
`wp-content`. The filter runs after the constant is read, so it can override it.

### `hwpua_export_retention_seconds` (filter)

| Parameter | Type | Default |
|---|---|---|
| `$retention_seconds` | `int` | `21600` (six hours) |

How long an export file stays on disk. Values below 60 are raised to 60. A destructive
action requires an export still on disk, so this also limits how long after exporting an
action can start.

### `hwpua_error` (action)

Fired whenever the plugin records an error through `hwpua_log_error()`.

| Parameter | Type | Description |
|---|---|---|
| `$message` | `string` | Operator-safe description of what failed |

The message is also written to the PHP error log when `WP_DEBUG` is on. With `WP_DEBUG` off,
this action is the only way to capture it:

```php
add_action(
	'hwpua_error',
	static function ( string $message ): void {
		error_log( 'Purge User Accounts: ' . $message );
	}
);
```

Examples of what fires it: an unreadable bundled ruleset, a refused per-user filter, a run
or job marked failed, an export guard file that could not be written, a failure to record a
job item, a run or job that could not be pruned.

## Hooks consumed

| Hook | Priority | Callback | Purpose |
|---|---|---|---|
| `before_woocommerce_init` | 10 | `Plugin::declare_woocommerce_compatibility` | Declare HPOS (`custom_order_tables`) compatibility |
| `admin_notices` | 10 | `Plugin::render_unsupported_notice` | Multisite only: why the plugin is inactive |
| `authenticate` | 100 | `Signin_Block::refuse_authentication` | Refuse blocked accounts |
| `determine_current_user` | 100 | `Signin_Block::refuse_current_user` | Refuse blocked accounts with an existing cookie |
| `allow_password_reset` | 100 | `Signin_Block::refuse_password_reset` | Refuse resets for blocked accounts |
| `admin_menu` | 10 | `Admin_Page::register_menu` | Tools → Purge User Accounts |
| `admin_post_hwpua_export` | 10 | `Admin_Page::handle_export` | CSV download |
| `admin_enqueue_scripts` | 10 | `Admin_Page::enqueue_assets` | CSS and JS on the plugin screen only |
| `wp_ajax_hwpua_start_run` | 10 | `Ajax::start_run` | |
| `wp_ajax_hwpua_step_run` | 10 | `Ajax::step_run` | |
| `wp_ajax_hwpua_estimate` | 10 | `Ajax::estimate` | |
| `wp_ajax_hwpua_preflight` | 10 | `Ajax::preflight` | |
| `wp_ajax_hwpua_start_job` | 10 | `Ajax::start_job` | |
| `wp_ajax_hwpua_step_job` | 10 | `Ajax::step_job` | |
| `wp_ajax_hwpua_save_preset` | 10 | `Ajax::save_preset` | |
| `wp_ajax_hwpua_delete_preset` | 10 | `Ajax::delete_preset` | |
| `wp_ajax_hwpua_import_preset` | 10 | `Ajax::import_preset` | |
| `admin_init` | 1 | `Plugin::maybe_upgrade_schema` | Install tables when the schema version changes |
| `admin_init` | 5 | `Plugin::ensure_export_directory` | Retry export directory provisioning |
| `admin_init` | 20 | `Plugin::purge_expired_exports` | Delete expired exports |
| `admin_notices` | 10 | `Plugin::render_export_directory_notice` | Export directory provisioning failure |
| `wp_login` | 10 | `Plugin::record_last_login` | Write `hwpua_last_login` |
| `init` | 10 | `Plugin::schedule_housekeeping` | Schedule `hwpua_housekeeping` daily |
| `hwpua_housekeeping` | 10 | `Plugin::run_housekeeping` | Delete expired exports, then prune expired runs and jobs |

The `admin_init` callbacks for the export directory, and both notices, act only for users
with `delete_users` (the unsupported notice: `activate_plugins`).

While `wp purge-users generate-fixture` runs, the fixture also filters
`wp_hash_password_options`, `send_password_change_email`, `send_email_change_email` and
`pre_wp_mail`, to use a cheap hash and send no mail.

The compatibility declaration calls
`FeaturesUtil::declare_compatibility( 'custom_order_tables', … )` when WooCommerce provides
it. The plugin reads both order stores; see [filters.md](filters.md#woocommerceorders).

## Constants

### Definable in `wp-config.php`

| Constant | Type | Effect |
|---|---|---|
| `HWPUA_EXPORT_DIR` | string | Absolute path for export files, used in place of the generated `wp-content` directory. The plugin creates it if it can. Prefer a path outside the web root |
| `HWPUA_ALLOW_FIXTURES` | truthy | Allows `generate-fixture` and `destroy-fixture` even when `wp_get_environment_type()` is `production` or `WP_DEBUG` is off |

```php
define( 'HWPUA_EXPORT_DIR', '/home/example/private/hwpua-exports' );
```

`WP_ENVIRONMENT_TYPE` and `WP_DEBUG` also matter: fixture commands are allowed without
`HWPUA_ALLOW_FIXTURES` only when the environment type is not `production` **and**
`WP_DEBUG` is on.

### Defined by the plugin

`HWPUA_NAME`, `HWPUA_VERSION`, `HWPUA_FILE`, `HWPUA_DIR`, `HWPUA_URL`,
`HWPUA_ADMIN_TEMPLATES_DIR`, `HWPUA_ASSETS_URL` and `HWPUA_DATA_DIR` are set by the plugin
file and should not be defined elsewhere. Option names, meta keys, table names and defaults
are namespaced constants in `constants.php`, such as `Purge_User_Accounts\TABLE_RUN_SCRATCH`.

## Global functions

| Function | Purpose |
|---|---|
| `hwpua_log_error( string $message ): void` | Record an error: PHP error log when `WP_DEBUG` is on, and the `hwpua_error` action |
| `hwpua_now(): string` | Current UTC time as `Y-m-d H:i:s` |
| `hwpua_resolve_cutoff( array $args ): string` | A `days_ago` or `date` argument as a UTC `Y-m-d H:i:s` cutoff, or `''` when neither is usable |

Stored options and user meta are listed in [database.md](database.md#options).
