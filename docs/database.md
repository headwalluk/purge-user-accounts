# Database

> **Status: pre-release.** Schema is provisional until Milestone 2 is built and measured.

Four custom tables, all prefixed `{$wpdb->prefix}hwpua_`.

## Tables

| Table | Holds |
|---|---|
| `hwpua_runs` | One row per query execution — spec, status, cursor, counts |
| `hwpua_run_items` | The result set: `(run_id, user_id, matched_rule)` |
| `hwpua_run_scratch` | Pre-materialised exclusion sets, dropped when a run finalises |
| `hwpua_jobs` | One row per action applied to a run |
| `hwpua_job_items` | Per-user **failures and skips only** |

## Design notes

**`hwpua_run_items` is deliberately narrow.** No denormalised login, email or display
name — those are one indexed join away and would go stale. `matched_rule` is the
exception, because which pattern fired is not derivable later, and it is what makes the
"why did this match?" column possible.

**`hwpua_job_items` records only failures and skips.** The mandatory pre-flight CSV is
already the record of who was targeted; writing 40,000 "this worked" rows is storage for
nothing. Every failure gets a reason, so a job reporting `38,402 succeeded, 6 failed` can
say which six and why.

## Retention

| Data | Kept |
|---|---|
| Runs and run items | 30 days |
| Scratch | Dropped at finalise |
| Jobs and job items | 12 months — the audit trail |
| CSV export files | **6 hours** — the plugin keeps no durable copy |

Pruning runs on a daily WP-Cron event. Housekeeping is the one job cron is right for: it
is idempotent and nothing breaks if it fires late.

## Uninstall

Uninstalling drops all four tables and removes plugin options. The stashed capabilities
written by the strip-roles action are **retained** unless explicitly purged — deleting
them would destroy the only record needed to reverse a quarantine.
