# Database

Five custom tables, plus options and user meta. Table names below omit the site's table
prefix; `Schema::table()` adds `$wpdb->prefix`.

The schema is installed with `dbDelta` on activation, and again on `admin_init` whenever
the stored `hwpua_schema_version` differs from `Schema::SCHEMA_VERSION` (`1.0.0`). There
are no foreign keys.

## Tables

### `hwpua_runs`

One row per query execution.

| Column | Type | Meaning |
|---|---|---|
| `run_id` | bigint unsigned, PK | |
| `filter_spec` | longtext | The validated criteria as JSON |
| `spec_hash` | char(64) | SHA-256 of `filter_spec` |
| `preset_name` | varchar(191), null | Preset slug when built with `wp purge-users run`; null from the admin screen |
| `status` | varchar(20) | `building`, `complete`, `failed` or `abandoned` |
| `stage_index` | smallint unsigned | Position in the stage plan |
| `stage_cursor` | bigint unsigned | Highest user ID handled in the current stage |
| `max_user_id` | bigint unsigned | `MAX(ID)` from `wp_users` when the run was created |
| `candidate_count` | bigint unsigned | Rows after the seed stage |
| `matched_count` | bigint unsigned | Rows at completion |
| `created_by` | bigint unsigned | User ID; `0` from WP-CLI without `--user` |
| `created_at` | datetime | UTC |
| `completed_at` | datetime, null | UTC |
| `failure_reason` | text, null | Operator-safe message when `failed` |

Keys: `status_created (status, created_at)`, `spec_hash (spec_hash)`.

### `hwpua_run_items`

The result set.

| Column | Type | Meaning |
|---|---|---|
| `run_id` | bigint unsigned | |
| `user_id` | bigint unsigned | |
| `matched_rule` | varchar(255), null | Reason label from a refine filter — in 1.0.0, the matching bad-signup rule |

Primary key `(run_id, user_id)`; key `run_rule (run_id, matched_rule)`.

No email, login or name is stored here. Those are joined from `wp_users` when displayed
or exported. `matched_rule` is stored because which rule fired cannot be worked out later.

### `hwpua_run_scratch`

Sets materialised by prepare stages for filters whose source table has no usable index on
its user column.

| Column | Type | Meaning |
|---|---|---|
| `run_id` | bigint unsigned | |
| `bucket` | varchar(32) | `commenter`, `wc_customer`, or an integration's own name |
| `user_id` | bigint unsigned | |

Primary key `(run_id, bucket, user_id)`. Rows are deleted when a run completes or fails.

### `hwpua_jobs`

One row per action applied to a run.

| Column | Type | Meaning |
|---|---|---|
| `job_id` | bigint unsigned, PK | |
| `run_id` | bigint unsigned | |
| `action` | varchar(64) | Action ID, such as `block-signin` |
| `action_args` | longtext, null | JSON: `dry_run`, `allow_privileged`, `reassign_to` as given |
| `status` | varchar(20) | `pending`, `running`, `complete`, `failed` or `cancelled` |
| `cursor_user_id` | bigint unsigned | Highest user ID processed |
| `processed` | bigint unsigned | Succeeded + failed + skipped |
| `succeeded`, `failed`, `skipped` | bigint unsigned | Counts |
| `export_name` | varchar(191), null | Basename of the newest export for the run when the job was created |
| `created_by` | bigint unsigned | User ID; `0` from WP-CLI without `--user` |
| `created_at` | datetime | UTC |
| `completed_at` | datetime, null | UTC, set on `complete` or `failed` |

Keys: `run_status (run_id, status)`, `created_at (created_at)`.

### `hwpua_job_items`

Per-user **failures and skips only**. Successes are counted on the job row, not written
here.

| Column | Type | Meaning |
|---|---|---|
| `job_item_id` | bigint unsigned, PK | |
| `job_id` | bigint unsigned | |
| `user_id` | bigint unsigned | |
| `result` | varchar(20) | `failed` or `skipped` |
| `message` | text, null | The reason, up to 2,000 characters |

Key: `job_result (job_id, result)`.

## Lifecycles

### Runs

```
building ──► complete
    │
    └──────► failed      (Run_Exception during a step)

building ──► abandoned   (Estimate matches, after counting)
```

Closing the browser tab stops a build; the run stays `building` until it is pruned. Run the
query again from the Build tab.

### Jobs

```
pending ──► running ──► complete
               │
               ├──────► failed      (Run_Exception during a step)
               └──────► cancelled   (wp purge-users stop)
```

Only the first step moves a job from `pending` to `running`; recording a chunk does not
change the status. Once a job is `cancelled`, any process driving it stops before its next
chunk. `wp purge-users resume` sets a cancelled job back to `running` and continues from its
cursor. `stop` and `resume` on a `complete` or `failed` job only report that it has
finished.

## Options

| Option | Holds |
|---|---|
| `hwpua_schema_version` | Installed schema version |
| `hwpua_activated_at` | UTC time of the most recent activation; the native login source's start date |
| `hwpua_export_dir_name` | Generated export directory name (autoloaded) |
| `hwpua_export_dir_error` | Last provisioning failure, shown as an admin notice |
| `hwpua_email_domain_allowlist` | Allowlist textarea, stored verbatim |
| `hwpua_disabled_rules` | Array of rule keys switched off |
| `hwpua_enabled_rules` | Array of rule keys switched on that ship `#! default-off` |
| `hwpua_custom_rules` | Site rules, in the same text format as the bundled file |
| `hwpua_presets` | Saved queries, keyed by slug |
| `hwpua_last_login_source` | ID of the preferred last-login source; empty for the default |
| `hwpua_fixture_created`, `hwpua_fixture_cohorts` | Development fixture progress only |

## User meta

| Key | Written by | Holds |
|---|---|---|
| `hwpua_last_login` | Every successful login | UTC datetime |
| `hwpua_signin_blocked` | `block-signin` | `1` while blocked |
| `hwpua_signin_blocked_at` | `block-signin` | UTC datetime |
| `hwpua_stashed_capabilities` | `strip-roles` | Array of the role slugs removed |
| `hwpua_stashed_at` | `strip-roles` | UTC datetime |
| `hwpua_is_fixture` | `generate-fixture` | Cohort name, marks generated users |

`unblock-signin` deletes the block keys; `restore-roles` deletes the stash keys.

## Retention

| Data | Removed |
|---|---|
| CSV export files | **Six hours** after they were written (`hwpua_export_retention_seconds`, minimum one minute) |
| Scratch rows | When the run completes or fails, or with the run |
| Runs, with their run items and scratch rows | **30 days** after `created_at` (`DEF_RUN_RETENTION_DAYS`) |
| Estimate runs (`abandoned`) | **One day** after `created_at` (`DEF_ABANDONED_RUN_RETENTION_SECONDS`) |
| Jobs and job items | **365 days** after `created_at` (`DEF_JOB_RETENTION_DAYS`) |

A run of any age is kept while it has a job that is `pending`, `running` or `cancelled`, so
a paused or stopped job can still be resumed. Once its jobs have completed or failed, the run
is pruned on the normal schedule; the job rows stay until their own 365 days are up.

The daily `hwpua_housekeeping` cron event purges expired exports, then runs
`Run_History::prune_expired()`. Items are deleted before their parent row. A failure to list
or delete rows is reported through `hwpua_log_error()` and the `hwpua_error` action, and the
remaining deletions go ahead.

Pruning depends on WP-Cron, which runs only when the site receives requests, so on a site
with little traffic runs and jobs are pruned late. The export purge also runs on every
`admin_init` for a user with `delete_users`, so export files do not wait for cron. It removes
only files matching `hwpua-export-*.csv` directly inside the export directory, and does not
follow symlinks.

The run and job retention periods are constants in 1.0.0; there is no filter for them.

## Export directory

| Setting | Location |
|---|---|
| `HWPUA_EXPORT_DIR` constant, or the `hwpua_export_dir` filter | That absolute path |
| Neither | `wp-content/<8 random lowercase letters and digits>/` |

The directory is created with `.htaccess` deny rules and an empty `index.php`. The plugin
warns on its own screen when the directory is inside `wp-content`; a path outside the web
root is preferable:

```php
define( 'HWPUA_EXPORT_DIR', '/home/example/private/hwpua-exports' );
```

Provisioning is retried on `admin_init` because activation from WP-CLI often runs as a
different system user from PHP-FPM. A failure is stored in `hwpua_export_dir_error` and
shown as an admin notice.

## Deactivation

Deactivating clears the `hwpua_housekeeping` event. Tables, options and user meta stay.
While the plugin is inactive, **sign-in blocks are not enforced** and logins are not
recorded.

## Uninstall

`uninstall.php`, run when the plugin is deleted from the Plugins screen:

1. Deletes the plugin's export files. If the export directory is inside `wp-content`, it
   also removes the guard files and the directory, when empty. A configured directory
   elsewhere is left in place.
2. Drops all five tables.
3. Deletes every plugin option: `hwpua_schema_version`, `hwpua_activated_at`,
   `hwpua_export_dir_name`, `hwpua_export_dir_error`, `hwpua_email_domain_allowlist`,
   `hwpua_disabled_rules`, `hwpua_enabled_rules`, `hwpua_custom_rules`, `hwpua_presets`,
   `hwpua_last_login_source`, `hwpua_fixture_created` and `hwpua_fixture_cohorts`.
4. Clears the `hwpua_housekeeping` event.

It does **not** delete:

- **`hwpua_stashed_capabilities` and `hwpua_stashed_at`** — the only record of which roles
  a strip removed. Deleting them would make an uninstall silently irreversible for anyone
  still quarantined.
- `hwpua_last_login`, `hwpua_signin_blocked` and `hwpua_signin_blocked_at` user meta. The
  block flags have no effect once the plugin is gone.
- `hwpua_is_fixture` user meta on generated users that were not removed with
  `destroy-fixture`.
