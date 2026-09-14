# WP-CLI

> **Status: pre-release.** Completed at Milestone 12.

## Presets are the interface

Building arbitrary filter combinations through command-line flags is a large design
problem for little gain. Instead: **the UI builds the query, the CLI runs it by name.**

```bash
wp purge-users run subscriber-sweep
```

Across a fleet:

```bash
for SITE_PATH in /var/www/*/web; do
    wp --path="${SITE_PATH}" purge-users run subscriber-sweep --format=json
done
```

Presets export and import as JSON, so a query defined once reaches every site. Import
validates against the target and **reports** what will not apply — a preset that silently
dropped its purchase filter would select and delete customers.

## Commands

| Command | Purpose |
|---|---|
| `wp purge-users doctor` | Environment report — run this first on an unfamiliar site |
| `wp purge-users run <preset>` | Build a run. Does not act on it. |
| `wp purge-users show <run-id>` | Summary and a sample of matches; `--random` to spot-check |
| `wp purge-users export <run-id>` | Streamed CSV |
| `wp purge-users act <run-id> --action=<action>` | Apply an action |
| `wp purge-users resume <job-id>` | Continue an interrupted job |
| `wp purge-users stop <job-id>` | Halt a running job |
| `wp purge-users list-runs\|list-presets\|list-actions` | Enumerate |

## Acting

```bash
wp purge-users act 142 --action=strip-roles --yes
wp purge-users act 142 --action=delete --reassign=1 --yes
wp purge-users act 142 --action=delete --dry-run
```

| Flag | Behaviour |
|---|---|
| `--yes` | Skips confirmation. Required for destructive actions in a non-interactive shell. |
| `--dry-run` | Runs every guard, reports what would happen, changes nothing |
| `--reassign=<id>` | **No default.** Delete errors without it. |

`--reassign` has no default deliberately. In the UI the choice is a visible radio button;
on the command line it must be typed. A silent default of "delete their content" is
exactly the kind of unnoticed destruction this plugin exists to avoid.

## Resumability

A job begun in the browser can be finished from the CLI, and vice versa — the cursor lives
on the job row, not in the session. Genuinely useful for a half-hour delete.
