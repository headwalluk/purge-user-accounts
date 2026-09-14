# Hooks

> **Status: pre-release.** Hook names are provisional until the code exists. Treat this
> page as intent, not contract, until v1.0.0.

## Hooks this plugin consumes

| Hook | Purpose |
|---|---|
| `wp_login` | Native last-login recording |
| `admin_menu` | Tools page registration |
| `wp_ajax_hwpua_*` | Stepper endpoints |
| `before_woocommerce_init` | HPOS compatibility declaration |

## Hooks this plugin emits

### Registration

| Hook | Type | Purpose |
|---|---|---|
| `hwpua_register_integrations` | filter | **The single extension point.** Add an integration, which in turn supplies filters, actions, a last-login source and export columns. |

There are deliberately no separate hooks for registering a filter, an action or a
last-login source on their own — an integration is the unit of extension. See
[integrations.md](integrations.md).

### Query lifecycle

| Hook | Type | Purpose |
|---|---|---|
| `hwpua_run_started` | action | A run has been created |
| `hwpua_run_stage_complete` | action | One stage of a run finished |
| `hwpua_run_complete` | action | A run finished; final count available |
| `hwpua_run_items_query` | filter | Modify the list-table query |

### Job lifecycle

| Hook | Type | Purpose |
|---|---|---|
| `hwpua_job_started` | action | An action job has begun |
| `hwpua_before_user_action` | action | Fired per user, before the action applies |
| `hwpua_after_user_action` | action | Fired per user, after |
| `hwpua_job_complete` | action | Job finished; counts available |
| `hwpua_user_skipped` | action | A guard skipped a user; carries the reason |

### Configuration

| Hook | Type | Purpose |
|---|---|---|
| `hwpua_chunk_size` | filter | Per-stage and per-action chunk sizes |
| `hwpua_guarded_user_ids` | filter | Add users that must never be selected |
| `hwpua_pattern_rules` | filter | Modify the compiled bad-signup ruleset |
| `hwpua_csv_columns` | filter | Add columns to the export |
| `hwpua_retention_days` | filter | Override retention periods |

## A note on `hwpua_guarded_user_ids`

This filter can only ever **add** protected users. It cannot remove the current user or
the last administrator from the guard list — those are enforced after the filter runs.
A plugin that could unguard them would be a privilege-escalation vector.
