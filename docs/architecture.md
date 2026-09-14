# Architecture

How the plugin is put together. For the tables it writes, see [database.md](database.md);
for the extension contract, see [integrations.md](integrations.md).

## The three ideas

### 1. A run is a stored result set

Executing a query writes the matching user IDs into `hwpua_run_items` instead of holding
them in a request. Paging, exporting and acting all read that stored list, so the set does
not shift while an operator reviews it, the export can be streamed, and an action can stop
and resume against the same list.

### 2. Integrations own the criteria

Criteria are grouped by the system that owns the data — WP Core, WooCommerce — one
integration per file in `integrations/`. An integration supplies filters, actions and a
last-login source, in any combination. If the system is absent, the whole integration is
unavailable with one stated reason.

The engine (`Run_Builder`, `Stepper`, `Job_Runner`) knows about stages, SQL predicates,
scratch buckets and ID cursors. It does not know about roles, posts or orders.

### 3. One stepper drives everything long-running

Building a run and executing a job are the same problem: do a bounded chunk of work, save
the cursor on the database row, return. The browser calls the stepper over AJAX, one
request at a time; WP-CLI calls it in a loop. Both call the same methods.

## File layout

```
purge-user-accounts.php     Bootstrap: constants, require list, activation hooks
constants.php               Option keys, meta keys, table names, defaults
functions-private.php       hwpua_log_error(), hwpua_now()
uninstall.php               Removes tables, options and export files
includes/                   One class per file, class-*.php
integrations/
    actions-wp-core.php     The eight WP Core actions
    integration-wp-core.php WP Core filters, integration and native login source
    integration-woocommerce.php
admin-templates/            tab-build.php, tab-results.php, tab-history.php, tab-settings.php, tab-help.php
assets/                     hwpua-admin.css, hwpua-admin.js (no build step)
data/bad-signup-rules.txt   Bundled pattern ruleset
```

## Bootstrap

`purge-user-accounts.php` defines `HWPUA_FILE`, `HWPUA_DIR`, `HWPUA_URL` and related path
constants, then requires every file explicitly in dependency order: environment and
settings, schema, the engine base classes (`Filter`, `Last_Login_Source`, `Integration`)
before the registry, the actions and integrations, then CLI, export and admin classes, and
finally `Plugin`. Integrations are listed explicitly rather than globbed, so a stray file in
`integrations/` is never loaded.

It then creates `$hwpua_plugin = new Plugin()` and calls `run()`.

`Plugin::run()` first hooks `before_woocommerce_init` to declare compatibility with
WooCommerce's HPOS order tables (`custom_order_tables`). It then asks
`Environment::is_supported()`. On multisite it registers only an `admin_notices` callback
explaining why the plugin is inactive, and nothing else. Otherwise
it registers, in order:

| Registered | Purpose |
|---|---|
| `Signin_Block::run()` | Sign-in block enforcement, on every request |
| `Cli::register()`, `Cli_Operator::register()` | WP-CLI commands, when running under WP-CLI |
| `Admin_Page::run()` | Tools menu, asset loading, the export handler |
| `Ajax::run()` | The nine `wp_ajax_hwpua_*` endpoints |
| `admin_init` priority 1 | `Schema::maybe_upgrade()` |
| `admin_init` priority 5 | Retry export-directory provisioning |
| `admin_init` priority 20 | Purge expired export files |
| `admin_notices` | Export-directory provisioning failure |
| `wp_login` | Record `hwpua_last_login` for every login |
| `init` / `hwpua_housekeeping` | Schedule and run the daily housekeeping event: purge expired exports, prune expired runs and jobs |

**Activation** installs the tables with `dbDelta`, attempts to create the export
directory (recording any failure for the admin notice), and stores `hwpua_activated_at`.
**Deactivation** clears the scheduled event; tables and settings stay.

## Classes

| Class | Role |
|---|---|
| `Plugin` | Registers every hook; housekeeping and login recording callbacks |
| `Environment` | Multisite refusal, WooCommerce and HPOS detection, capability check, capabilities meta key |
| `Settings` | Typed option reads and writes |
| `Schema` | Table definitions, install, upgrade, drop |
| `Integration` | Base class for integrations |
| `Integration_Registry` | Collects integrations from `hwpua_register_integrations`; flattens filters; refuses per-user filters |
| `Filter` | Base class for criteria |
| `Action`, `Action_Result`, `Action_Registry` | Base class, per-chunk outcome, collection ordered by destructiveness |
| `Last_Login_Source`, `Coverage_Report`, `Last_Login` | Source description, coverage figures, active-source selection and the Unknown/Never judgement |
| `Stage` | One entry in a run's stage plan |
| `Run`, `Run_Builder`, `Stepper` | Run row wrapper; spec validation and planning; stage execution |
| `Run_Exception` | Operator-safe failure that stops a run or job |
| `Job`, `Job_Runner` | Job row wrapper; chunk execution with guards |
| `Guards` | Protected users: self, privileged roles, last administrator |
| `Preflight` | The checklist shown before an action |
| `Signin_Block` | Block flag and its enforcement |
| `Pattern_Ruleset`, `Domain_Allowlist` | Bad-signup rules and the email domain allowlist |
| `Preset` | Saved queries, validation, JSON export and import |
| `Export_Directory`, `Csv_Export` | Where exports live and how long; streamed CSV writer |
| `Run_History`, `Users_List_Table` | Read-side helpers; the Results table |
| `Admin_Page`, `Ajax` | Tools screen and export handler; AJAX endpoints |
| `Cli_Operator`, `Cli` | Operator commands; development commands |
| `Fixture`, `Benchmark` | Synthetic user population; stage timing |

## Runs

### The filter specification

A run is built from a list of criteria:

```json
[
    { "id": "wp-core.role", "sense": "has", "args": { "roles": [ "subscriber" ] } },
    { "id": "woocommerce.orders", "sense": "has_not", "args": {} }
]
```

`Run_Builder::validate()` rejects the whole specification if any criterion is malformed,
unknown, unavailable, or has a sense other than `has` or `has_not`. A missing `sense`
defaults to `has_not`; a filter whose `supports_sense()` is false is always given `has`. A
criterion whose `get_argument_error()` returns a message is refused as incomplete. Nothing
is dropped silently, and no criterion is left to produce a match-nothing predicate that
`has_not` would invert into "everyone".

### The stage plan

`Run_Builder::plan()` turns the specification into an ordered list of stages. The plan is
re-derived from the stored specification on every step, so it must be deterministic.

| Order | Stage | What it does |
|---|---|---|
| 1 | `prepare` | One per distinct scratch bucket. Runs the filter's scratch statements once, filling `hwpua_run_scratch` |
| 2 | `seed` | Chunked `INSERT IGNORE INTO hwpua_run_items ... SELECT` over `wp_users` by ID range, with every SQL criterion folded into the `WHERE` clause |
| 3 | `refine` | One per refine-kind criterion. Reads run items in chunks, passes rows to `evaluate_chunk()`, deletes the rows it drops |
| 4 | `finalise` | Deletes scratch rows, counts the items, marks the run complete |

### Seed

`Run::create()` stores `max_user_id = MAX(ID)` from `wp_users`. The seed walks
`u.ID > cursor AND u.ID <= cursor + chunk` up to that ceiling, so accounts created during a
long build are left for the next run. The chunk is 25,000 IDs (`hwpua_seed_chunk_size`,
floor 10). Because chunks are ID ranges, the number of seed steps follows the ID space, not
the user count, on a site with many deleted accounts.

Each SQL criterion contributes `( predicate )` for `has` and `NOT ( predicate )` for
`has_not`. The guarded user IDs from `Guards::get_excluded_user_ids()` — the current user
plus anything added through `hwpua_guarded_user_ids` — are excluded here with
`u.ID NOT IN (...)`. The user IDs never enter PHP.

### Refine

A refine stage selects `u.ID` plus the filter's required columns from `hwpua_run_items`
joined to `wp_users`, `WHERE ri.user_id > cursor ORDER BY ri.user_id LIMIT chunk`.
`evaluate_chunk()` returns the IDs that fail the filter's positive condition; the engine
inverts that list for `has_not`. Survivors' match labels are written to
`hwpua_run_items.matched_rule`.

### Invariants

- **Only the seed adds rows.** Every later stage only deletes. An interrupted refine stage
  has left a superset of the right answer, so resuming converges on it.
- **Cursors are user IDs**, never offsets, stored on the run row as `stage_index` and
  `stage_cursor`.
- **Replaying a step is harmless.** The seed uses `INSERT IGNORE`; refine deletes are
  idempotent.
- **Fail closed.** A scratch statement, seed insert, refine read or refine delete that
  returns a database error throws `Run_Exception`. `Stepper::step()` marks the run
  `failed` with the message, deletes its scratch rows and reports `aborted`. A source that
  could not be read never looks like a source with no rows.

### Estimates

**Estimate matches** on the Build tab runs a full build in one request, reads the count,
and marks the run `abandoned` so it does not appear in History. Abandoned runs are pruned
after a day.

## Jobs

`Job::create()` refuses unless the run is `complete`, is not stale (completed within
`hwpua_run_stale_seconds`, default one day), and — for actions that require it — an export
file for the run exists on disk.

`Job_Runner::step()` first checks the job's status. A `cancelled` job returns a finished
progress payload marked `stopped`, doing no work; a `pending` job is set to `running`. It
then loads the next chunk of user IDs above the job's `cursor_user_id`, and:

1. Re-applies `Guards::find_protected()` to that chunk. Protected users are recorded as
   skipped with a reason.
2. On a dry run, counts the remaining users as succeeded without calling the action.
3. Otherwise calls `Action::apply()` with the remaining IDs.
4. Writes failures and skips to `hwpua_job_items`, adds to the counts, and moves the cursor
   to the highest ID in the chunk.

When no IDs remain, the job is marked `complete`. A `Run_Exception` marks it `failed`.
Recording a chunk never changes the status, so a `wp purge-users stop` issued while a chunk
is in flight is honoured before the next one, whichever process is driving the job.

The remaining-time estimate uses the action's declared `get_rate_per_second()` until the
job has processed at least 100 users over at least two seconds, then observed throughput
since the job was created.

See [actions.md](actions.md) for guards, pre-flight and confirmation.

## The browser stepper

| Endpoint (`wp_ajax_`) | Does |
|---|---|
| `hwpua_estimate` | Validate, build to completion, return count and warnings |
| `hwpua_start_run` | Validate, create the run, return run ID, stage count and warnings |
| `hwpua_step_run` | Advance a run by one step |
| `hwpua_preflight` | Return the pre-flight checklist for a run and action |
| `hwpua_start_job` | Rebuild pre-flight, verify the typed confirmation, create the job |
| `hwpua_step_job` | Advance a job by one chunk |
| `hwpua_save_preset`, `hwpua_delete_preset`, `hwpua_import_preset` | Saved queries |

Every endpoint checks the `hwpua_admin` nonce and then the `delete_users` capability. The
client sends only IDs; the cursor is read from the database row.

`assets/hwpua-admin.js` sends one step, waits for the response, and sends the next. A
network failure is retried up to three times with a one-, two- and three-second delay; a
server-reported abort stops the loop and shows the message. When a build finishes, the
browser moves to the Results tab. Closing the tab stops a build, which must be run again,
and pauses a job, which can be resumed with WP-CLI. The admin screen has no stop or resume
control.

## Why not WP-Cron

WP-Cron runs only when the site receives traffic, and the sites that accumulate junk
accounts are usually quiet. A build or job that waited for visitors would stall. The
browser or the shell drives the work instead, which also shows progress as it happens.

Cron is used only for the daily `hwpua_housekeeping` event, which deletes expired export
files and prunes expired runs and jobs (see [database.md](database.md#retention)). Nothing
breaks if it fires late. The export purge also runs on `admin_init` for users with
`delete_users`, so personal data on disk does not wait for traffic.

## Exports

`Csv_Export::write()` refuses an incomplete run, then streams rows in chunks of 2,000 from
one joined query (users, capabilities, first and last name, last-login meta), clearing the
user meta cache after each chunk. The file is written to the export directory as
`hwpua-export-run-<run_id>-<16 random characters>.csv` and chmod `0600`.
`Admin_Page::handle_export()` — POST only, nonce and capability checked — writes a fresh
file on each download and streams it with `readfile()`. See [database.md](database.md)
for location and retention.

## Error recording

`hwpua_log_error( $message )` writes to the PHP error log when `WP_DEBUG` is on and always
fires the `hwpua_error` action. Per-user action failures are recorded in `hwpua_job_items`;
run and job failures set the row's status (and `failure_reason` on runs).

## Conventions

- Hooks are registered in `Plugin::run()` and implemented in the class that owns the
  behaviour
- Option names, meta keys, table names and defaults come from `constants.php`
- `includes/class-*.php`, one class per file, `snake_case` methods
- Templates print with `printf()` and `echo`
- No `return` from inside a loop; guard clauses at the top of a function are fine
- Every `catch` rethrows, or records the failure in `hwpua_job_items` or through
  `hwpua_log_error()`
- Users are changed only through WordPress APIs — `wp_delete_user()`, `wp_set_password()`,
  `WP_User::set_role()` — never raw SQL
