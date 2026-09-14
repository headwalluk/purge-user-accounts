# WP-CLI

All commands are under `wp purge-users`. They call the same stepper, job runner, pre-flight
and export code as the admin screen, in a loop instead of over AJAX.

## Before you start

- **Pass `--user`.** WP-CLI performs no capability check of its own. Without `--user` there
  is no current user: nothing is protected by the "your own account" guard, and runs and
  jobs are recorded with `created_by` 0.
  ```bash
  wp --user=site-admin purge-users act 142 --action=block-signin
  ```
- The commands register only on a supported site. On multisite, `wp purge-users` does not
  exist.
- Run `wp purge-users doctor` first on an unfamiliar site.

## Presets are the interface

There are no flags for building a query. **The admin screen builds the query; the command
line runs it by name.** Save the ticked criteria as a preset on the Build tab, or import a
preset file, then `wp purge-users run <slug>`.

## Commands

| Command | Purpose |
|---|---|
| `doctor` | Report what the site supports |
| `run <preset>` | Build a run from a preset |
| `show <run-id>` | Summarise a run and sample its matches |
| `export <run-id>` | Write the run's CSV |
| `act <run-id> --action=<action>` | Apply an action |
| `resume <job-id>` | Continue a job |
| `stop <job-id>` | Mark a job cancelled |
| `list-runs` | Recent runs |
| `list-presets` | Saved presets and whether they can run here |
| `list-actions` | Available actions |
| `preset-export <preset>` | Print a preset as JSON |
| `preset-import <file>` | Import a preset from JSON |
| `preset-delete <preset>` | Forget a preset |

### `doctor`

```
wp purge-users doctor
```

Prints the environment type, whether the site is supported, whether the tables are
installed, the user count, WooCommerce and its order store, active pattern rules, allowlist
entries, the export directory (noting when it is inside the web root) and whether it is
writable by the current system user, and the active last-login source with its earliest
record, users with a record, and the Unknown cohort. Warns when there is no last-login
source.

### `run`

```
wp purge-users run <preset> [--porcelain]
```

| Argument | Description |
|---|---|
| `<preset>` | Preset slug |
| `--porcelain` | Print only the run ID |

Checks the preset against the site first; if any integration or criterion is missing or
unavailable, prints the problems and stops without creating a run. Unknown role slugs are
printed as warnings and the run continues. Otherwise it creates the run and prints one line
per stage with the running match count, then the total and the `export` and `act` commands
to use next. The run records the preset slug in `preset_name`.

The warning about criteria that cannot overlap is shown only on the Build tab.

### `show`

```
wp purge-users show <run-id> [--limit=<count>] [--random]
```

| Argument | Description |
|---|---|
| `<run-id>` | Run to show |
| `--limit=<count>` | Sample size. Default 20 |
| `--random` | Sample randomly instead of by user ID |

Prints status, criteria and match count; a "Why they matched" summary when there is more
than one reason; and a table of ID, login, email and match reason. Use `--random`: the first
rows by ID are the oldest signups and look uniform whether or not the query is right.

### `export`

```
wp purge-users export <run-id> [--file=<path>]
```

| Argument | Description |
|---|---|
| `<run-id>` | A complete run |
| `--file=<path>` | Also copy the CSV here, chmod `0600` |

Always writes the file into the export directory, which is what satisfies the export check
for `strip-roles`, `scramble-password` and `delete`. That copy is deleted after six hours.
Without `--file`, the command warns you to copy it somewhere safe.

### `act`

```
wp purge-users act <run-id> --action=<action> [--reassign=<user-id>] [--dry-run] [--yes] [--allow-privileged]
```

| Argument | Description |
|---|---|
| `<run-id>` | Run to act on — complete, and finished within the last day |
| `--action=<action>` | Action ID; see `list-actions` |
| `--reassign=<user-id>` | **Required for `delete`.** User to receive their posts, or `0` to delete posts too. No default |
| `--dry-run` | Run every check and guard, change nothing |
| `--yes` | Answer the confirmation prompt |
| `--allow-privileged` | Also act on users whose role can delete users. Your own account and the last administrator stay protected |

Prints the action, match count and estimated duration, then the pre-flight checklist (`✓`
ok, `⚠` warning, `✗` blocking). Any blocking line stops the command with nothing changed.

For `scramble-password` and `delete`, a prompt follows unless this is a dry run; `--yes`
answers it, and in a non-interactive shell without `--yes` the command stops. The prompt
does not ask for the match count — that is the admin screen's control.

The job then runs to completion, printing processed, succeeded, skipped and failed counts at
most once a second, and finishes with the job ID. The export check applies to dry runs too,
so export first. If the job is stopped from elsewhere while it runs, the command finishes the
chunk in hand, stops, and prints a warning with the `resume` command.

### `resume`

```
wp purge-users resume <job-id>
```

Continues a job from its saved cursor — whether it was started in the browser or on the
command line, stopped with `stop`, or interrupted. A stopped job is set back to running
first. A job that already completed or failed is reported as "That job has already
finished."

Guards are re-applied to every chunk. The run's staleness is not re-checked on resume.

### `stop`

```
wp purge-users stop <job-id>
```

Marks the job `cancelled` so it can be resumed later. Any process driving the job — a
WP-CLI `act` or `resume`, or a browser tab — checks the status before each chunk, lets the
chunk in hand finish, and stops. A job that already completed or failed is reported as
"That job has already finished." The cursor is saved after every chunk, so Ctrl+C in the
terminal also leaves a CLI-driven job resumable.

### `list-runs`

```
wp purge-users list-runs [--status=<status>] [--format=<format>]
```

| Argument | Description |
|---|---|
| `--status=<status>` | `building`, `complete` or `failed` |
| `--format=<format>` | `table` (default), `csv`, `json` or `yaml` |

Lists the 25 most recent runs, excluding estimates: ID, status, match count, created time
(UTC) and criteria. The status filter applies to those 25.

### `list-presets`

```
wp purge-users list-presets [--format=<format>]
```

Slug, label, number of criteria, required integrations, and `usable_here`.

### `list-actions`

```
wp purge-users list-actions [--format=<format>]
```

Action ID, label, destructiveness (`risk`), reversibility and whether an export is required,
least destructive first.

### `preset-export`

```
wp purge-users preset-export <preset> [--file=<path>]
```

Prints the preset as pretty-printed JSON, or writes it to `--file`.

### `preset-import`

```
wp purge-users preset-import <file> [--dry-run]
```

| Argument | Description |
|---|---|
| `<file>` | Path to an exported preset, or `-` for standard input |
| `--dry-run` | Validate and report without storing |

Reports each required integration and any problem. It refuses to import when an integration
is not installed or not available, or a criterion cannot be used; unknown role slugs are
warnings. The preset is stored under a slug derived from its `label`; an existing preset
with that slug is replaced.

### `preset-delete`

```
wp purge-users preset-delete <preset> [--yes]
```

Runs already built from the preset are unaffected.

## Preset format

```json
{
    "hwpua_preset": 1,
    "slug": "dormant-subscribers",
    "label": "Dormant subscribers",
    "description": "Subscribers with nothing attached",
    "filter_spec": [
        { "id": "wp-core.role", "sense": "has", "args": { "roles": [ "subscriber" ] } },
        { "id": "wp-core.content", "sense": "has_not", "args": {} },
        { "id": "wp-core.comments", "sense": "has_not", "args": {} },
        { "id": "woocommerce.orders", "sense": "has_not", "args": { "include_guest_email": true } },
        { "id": "wp-core.login-record", "sense": "has_not", "args": { "include_unknown": false } },
        { "id": "wp-core.registered", "sense": "has", "args": { "direction": "before", "days_ago": 90 } }
    ]
}
```

| Key | Required | Notes |
|---|---|---|
| `hwpua_preset` | yes | Must be truthy |
| `filter_spec` | yes | List of `{ id, sense, args }`; see [filters.md](filters.md) for every ID and argument |
| `label` | no | Name, and the source of the slug on import. Falls back to `slug` |
| `slug` | no | Informational; not used on import |
| `description` | no | |

- A criterion with no `sense` is treated as `has_not`.
- A preset must contain at least one criterion; an empty one would select every account.
- `wp-core.registered` has no sense; whatever is stored, it is applied as `has`.
- A criterion with incomplete arguments — no roles, no post types or statuses, no cutoff —
  is refused when the preset is saved, imported or run.

Presets are stored in the `hwpua_presets` option. The Build tab's **Export** button shows the
same JSON, on one line, for copying; **Import…** accepts it pasted.

## Worked examples

### Review, then block

```bash
wp purge-users doctor
wp purge-users list-presets

wp --user=site-admin purge-users run dormant-subscribers
wp purge-users show 142 --random --limit=50
wp purge-users export 142 --file="${HOME}/purge-run-142.csv"

wp --user=site-admin purge-users act 142 --action=block-signin
```

### Delete, after a dry run

```bash
wp --user=site-admin purge-users run dormant-subscribers --porcelain
wp purge-users export 150 --file="${HOME}/purge-run-150.csv"

wp --user=site-admin purge-users act 150 --action=delete --reassign=0 --dry-run
wp --user=site-admin purge-users act 150 --action=delete --reassign=0 --yes
```

### Capture the run ID in a script

```bash
RUN_ID=$(wp --user=site-admin purge-users run dormant-subscribers --porcelain)
wp purge-users show "${RUN_ID}" --random
wp purge-users export "${RUN_ID}" --file="${HOME}/purge-run-${RUN_ID}.csv"
```

### An interrupted job

```bash
wp --user=site-admin purge-users act 150 --action=strip-roles
# Ctrl+C, or the browser tab closed part-way
wp --user=site-admin purge-users resume 37
```

### Moving a preset between sites

```bash
wp --path="${SOURCE_SITE_PATH}" purge-users preset-export dormant-subscribers --file=dormant-subscribers.json
wp --path="${TARGET_SITE_PATH}" purge-users preset-import dormant-subscribers.json --dry-run
wp --path="${TARGET_SITE_PATH}" purge-users preset-import dormant-subscribers.json
```

### Building the same query across many sites

```bash
PRESET_FILE="${HOME}/dormant-subscribers.json"

for SITE_PATH in /srv/sites/*/public; do
    echo "== ${SITE_PATH}"
    wp --path="${SITE_PATH}" purge-users preset-import "${PRESET_FILE}" \
        && wp --path="${SITE_PATH}" --user=site-admin purge-users run dormant-subscribers
done
```

Build and review across sites; act on each site separately, after reading its result.

## Development commands

Commands for generating and measuring a synthetic population.

### `generate-fixture` and `destroy-fixture`

```
wp purge-users generate-fixture [--users=<count>] [--batch-size=<count>]
wp purge-users destroy-fixture
```

| Argument | Default |
|---|---|
| `--users=<count>` | 40000 |
| `--batch-size=<count>` | 500 |

`generate-fixture` creates subscribers in fixed cohorts — legitimate customers and dormant
accounts, authors, commenters, guest-then-registered customers, academic addresses,
throwaway bots and plausible bots — marks each with `hwpua_is_fixture`, and resumes from
previous progress. It uses a cheap password hash and suppresses mail while it runs.
`destroy-fixture` deletes every user carrying that marker with `wp_delete_user()`.

Both are **refused** unless `HWPUA_ALLOW_FIXTURES` is defined and truthy, or the environment
type is not `production` and `WP_DEBUG` is on. The error names the condition that failed.

### `benchmark`

```
wp purge-users benchmark [--spec=<json>] [--explain] [--compare-strategies]
```

| Argument | Description |
|---|---|
| `--spec=<json>` | Filter specification as JSON. Default: none, measuring the seed alone |
| `--explain` | Print MySQL's execution plan for the seed statement |
| `--compare-strategies` | Time three ways of expressing "has no comments" |

Builds a run to completion and prints the match count, steps, elapsed time, peak memory and
per-stage timings. `benchmark` is **not** gated by the fixture conditions: it creates real
runs (left as `complete`, or `building` after `--explain`) and writes to the scratch table,
but changes no users.
