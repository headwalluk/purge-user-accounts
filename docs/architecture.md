# Architecture

> **Status: pre-release.** Finalised at Milestone 13. The working design is under review.

## The three ideas

Everything else is detail.

### 1. A run is a materialised, persisted result set

Executing a query writes its matching user IDs into a custom table rather than holding
them in a request. Paging, exporting and acting all address that stored snapshot.

This gives stable paging (a live query shifts as people register), streamed export,
resumable deletion, and a permanent audit trail of what was selected and when.

### 2. Integrations own the criteria

Criteria are grouped by the system that owns the data — WP Core, WooCommerce, LearnDash —
one class per file in `integrations/`. An integration supplies filters, actions, a
last-login source and export columns; any subset.

Grouping this way means availability is answered once. WooCommerce being inactive is one
disabled section with one explanation, not three checkboxes each repeating it.

The engine itself knows nothing about roles, posts or orders — only stages, predicates and
cursors. See [integrations.md](integrations.md).

### 3. One stepper drives everything long-running

Building a run and executing a job are the same shape of problem: do a bounded amount of
work, record the cursor, return. The browser drives the stepper over AJAX with a progress
bar; WP-CLI drives it with a plain loop. Same code path, so the two cannot diverge.

## Why not WP-Cron

WP-Cron fires on inbound traffic, and sites that accumulate tens of thousands of bot
accounts are precisely the quiet ones. A job depending on visitors to progress stalls
exactly where it is needed most.

The stepper also gives the operator a progress bar and a stop button. For an operation
that deletes people, watching it happen is a feature.

## Bootstrap

`purge-user-accounts.php` defines constants, requires each class file in dependency
order, and creates a global instance calling `Plugin::run()`.

Two checks happen before any hook is registered:

- **Multisite** — the plugin registers only an explanatory notice on a network install
- **Capability** — the gating capability is `delete_users`, the one that describes what
  the plugin actually does

## Conventions

- Hooks registered in `Plugin::run()`, implemented in their own classes
- Constants in `constants.php`; no magic strings
- `includes/class-*.php`, one class per file
- Templates use `printf()`/`echo`, never inline HTML
- Single entry, single exit where reasonable; never `return` from inside a loop
- Every `catch` rethrows or records durably — no silent swallowing
