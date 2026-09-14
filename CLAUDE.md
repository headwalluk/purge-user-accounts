# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Purge User Accounts is a WordPress plugin for finding and removing junk user accounts —
botnet signups, sleeper accounts, dormant subscribers — on sites carrying 100,000+ users.
It provides a Tools-menu admin page and WP-CLI commands.

- **Namespace:** `Purge_User_Accounts`
- **Text Domain:** `purge-user-accounts`
- **Constant prefix:** `HWPUA_` — function/option/table prefix `hwpua_`
- **PHP:** 8.2+ (do NOT use `declare(strict_types=1)` — breaks WordPress interop)
- **WordPress:** 6.8+ (guarantees bcrypt password hashing)
- **WooCommerce:** optional — purchase filters self-disable when absent
- **Multisite:** detected and refused in v1. Never assume single-site without checking.
- **No build system** — no npm, no Composer, no bundler. Assets are plain CSS/JS.
- **Distribution:** public GitHub repo, not wordpress.org. Self-contained; no external
  file dependencies at runtime.

## Commands

```bash
phpcs                  # Check WordPress coding standards compliance
phpcbf                 # Auto-fix coding standards violations
phpcs includes/        # Check specific directory
```

Always run `phpcs` before committing. Config is in `phpcs.xml`.

## Non-negotiables

These are the rules this plugin exists to honour. Breaking one is a defect, not a style
preference.

1. **Never load user rows you do not need.** 100,000 `stdClass` rows cost 52 MB; 100,000
   integer IDs cost 2 MB. `get_users()` returning `WP_User` objects is never acceptable
   at scale — measure before assuming. See `dev-notes/11-scale-and-performance.md`.
2. **Set operations happen in SQL, not PHP.** Building a result set is
   `INSERT INTO ... SELECT`; the IDs need never enter PHP memory at all.
3. **Never delete or modify users with raw SQL.** `wp_delete_user()` and
   `wp_set_password()` only — they fire the hooks that WooCommerce and others depend on.
4. **Never use `wp_update_user()` to change a password.** It emails the user. Firing
   40,000 password-change notifications at throwaway domains would destroy the site's
   sending reputation. `wp_set_password()` is silent; use it.
5. **Re-apply safety guards at execution time, not selection time.** A run built on
   Tuesday and executed on Thursday may contain someone promoted to administrator since.
6. **Every failure is recorded durably.** No empty `catch`. A caught error either
   rethrows or writes a row to `hwpua_job_items` with a reason. Clean logs and broken
   behaviour is the failure mode this plugin must never have.

## Architecture

### Integrations

Criteria are grouped by **the system that owns the data**, one class per file in
`integrations/`, each self-registering on `hwpua_register_integrations`. An integration
supplies filters, actions, a last-login source and export columns — any subset.

v1.0.0 ships `integration-wp-core.php` and `integration-woocommerce.php`. LearnDash follows
in v1.1.

**The engine must know nothing about roles, posts or orders** — only stages, predicates,
scratch buckets and cursors. If engine code starts naming a WordPress concept, it belongs
in an integration.

A filter contributes in exactly one of three ways:

```php
get_predicate( array $args, int $run_id ): array        // preferred - folded into the seed statement
get_scratch_queries( int $run_id, array $args ): array  // fills a scratch bucket the predicate joins
evaluate_chunk( array $rows, array $args ): array       // IDs to DROP; receives a CHUNK
```

**There is no `evaluate_user()`, and none may be added.** Per-user iteration hydrates rows
at 52 MB per 100,000 (measured) and issues N queries per integration, forfeiting the
`INSERT ... SELECT` seed the whole memory design rests on. See
`dev-notes/12-integration-framework.md` §3.

Filter IDs are namespaced by integration and name the positive condition, so `has_not`
reads naturally: `woocommerce.orders`. Core action IDs are bare: `block-signin`, `delete`.

A filter whose arguments cannot express a usable criterion returns a reason from
`get_argument_error()`, and the run is refused. Never return a match-nothing predicate
instead: `has_not` inverts it into match-everyone. A filter with `supports_sense()` false
is always applied as `has`.

### Runs and jobs

A **run** is a materialised result set, persisted in custom tables. A **job** is one
action applied to one run. Both advance through a stepper — a chunked, resumable loop
driven by AJAX in the browser and by a plain loop under WP-CLI. Same code path for both.

Custom tables: `hwpua_runs`, `hwpua_run_items`, `hwpua_jobs`, `hwpua_job_items`.
Schema in `docs/database.md`.

### Why not WP-Cron

WP-Cron needs traffic to fire, and the sites that accumulate bot accounts are exactly the
quiet ones. The stepper is driven by the browser instead. Do not reach for
`wp_schedule_event` to solve a batching problem here.

## Key Conventions

- Register all hooks in `Plugin::run()`, implement in respective classes
- Use constants from `constants.php` — never hardcode option names or magic values
- Class files are `includes/class-*.php`, one class per file, `snake_case` methods
- Templates must use `printf()`/`echo` — no inline HTML with PHP snippets
- Single entry, single exit where reasonable. **Never `return` from inside a loop** —
  set the result, `break`, return at the end. Early `return` guard clauses at the top of
  a function are fine when short-circuiting for security or performance.
- `.forEach()` with a bare `return` to skip an item is a `continue` in disguise — write
  `for...of` with an actual `continue`. `.map()`/`.filter()`/`.find()` returning a value
  is correct.
- Name things for what they are. No single-character or cryptic identifiers, in any
  language, including throwaway shell one-liners.
- Security: nonce verification, capability check, input sanitization, output escaping on
  every admin form and AJAX endpoint. The capability is `delete_users`, not
  `manage_options`.
- Comments describe **how the code works**, not the background to the decision.
  Rationale, alternatives and evidence belong in `dev-notes/` — reference the document
  from the comment rather than restating it.
- No i18n tooling: source strings are en-GB. `__()` / `esc_html__()` wrappers are kept
  for their escaping half and use the `purge-user-accounts` domain, so the plugin stays
  translatable later without a rewrite.

## Testing against real data

`dev-notes/` is gitignored because development references real client sites and real
user data from the Headwall fleet. Keep it that way. Never move corpus findings,
hostnames or user records into `docs/`, `README.md` or a commit message.

## Commit Messages

```
type: brief description

- Detail 1
- Detail 2
```

Types: `feat:` `fix:` `refactor:` `chore:` `docs:` `style:` `test:`

## Reference Files

`docs/` is the maintained entry point for how the plugin actually works:

- `docs/architecture.md` — plugin structure, classes, conventions
- `docs/database.md` — custom tables, schema, retention
- `docs/filters.md` — selection criteria and exact semantics
- `docs/actions.md` — bulk actions, guards, reversibility
- `docs/integrations.md` — the integration contract for developers
- `docs/hooks.md` — hooks consumed and emitted
- `docs/cli.md` — WP-CLI commands and presets
- `docs/operators-guide.md` — site-owner guide

Design and planning material (not distributed):

- `dev-notes/00-project-tracker.md` — milestones, open questions, decision log
- `dev-notes/01-requirements.md` through `12-integration-framework.md` — the design
- `dev-notes/reference/fleet-census-2026-09-12.md` — what to integrate with, and what not to
- `dev-notes/reference/` — benchmarks and corpus measurements
