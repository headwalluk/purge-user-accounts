# Purge User Accounts

![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-21759B?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-optional-7F54B3?logo=woocommerce&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv2%20or%20later-blue)
![Status](https://img.shields.io/badge/status-1.0.0-2ea44f)

Find, audit and remove junk user accounts on WordPress sites — botnet signups, sleeper
accounts and dormant subscribers — on sites carrying tens of thousands of users.

Built by [Headwall Hosting](https://headwall-hosting.com) after years of clearing botnet
signups off client sites by hand with SQL and WP-CLI.

## What it does

1. **Build a query** from criteria — roles, authored content, comments, WooCommerce
   orders, registration date, login record, bad-signup email and username patterns.
   Criteria combine with AND, and each can be used as *has* or *has none*.
2. **Run it.** The matching user IDs are stored server-side, so paging, exporting and
   acting all address the same fixed set. The results table says *why* each account
   matched.
3. **Export a CSV** before changing anything.
4. **Act on the result** — force logout, revoke application passwords, strip or restore
   roles, block or allow sign-in, scramble passwords, or delete — behind pre-flight checks
   and, for the destructive actions, a typed confirmation.

Builds and jobs run in chunks, driven by the browser or by WP-CLI. A paused or stopped job
can be resumed from WP-CLI.
Saved queries (presets) can be exported as JSON and run by name on other sites.

## Requirements

- WordPress 6.8 or later, PHP 8.2 or later
- WooCommerce is optional; the purchase criterion is unavailable without it
- Single-site installs only — the plugin refuses to run on multisite
- Operators need the `delete_users` capability

## Installation

Download the zip from the
[releases page](https://github.com/headwalluk/purge-user-accounts/releases) and upload it
under **Plugins → Add New → Upload Plugin**, or:

```bash
wp plugin install purge-user-accounts-v1.0.0.zip --activate
wp purge-users doctor
```

The screen is under **Tools → Purge User Accounts**.

## Documentation

- [docs/operators-guide.md](docs/operators-guide.md) — for site owners: how to use this safely
- [docs/filters.md](docs/filters.md) — every selection criterion and its exact semantics
- [docs/actions.md](docs/actions.md) — what each action changes, and what it cannot undo
- [docs/cli.md](docs/cli.md) — WP-CLI commands and presets
- [docs/architecture.md](docs/architecture.md) — plugin structure, runs, jobs and the stepper
- [docs/integrations.md](docs/integrations.md) — the developer contract for adding criteria
- [docs/hooks.md](docs/hooks.md) — hooks and constants
- [docs/database.md](docs/database.md) — custom tables, stored data, uninstall

## Safety

This plugin deletes people. Read [docs/operators-guide.md](docs/operators-guide.md) before
running anything destructive — particularly the section on last-login data, which is the
most common source of false positives.

- **Block sign-in is the reversible way to take accounts out of use.** Stripping roles does
  not stop an account signing in, and scrambling a password does not stop anyone who can
  read the mailbox.
- **Guards are applied when the action runs**, chunk by chunk: your own account and the
  last administrator are never touched, and accounts whose role can delete users are
  skipped unless you opt in from WP-CLI. They can still appear in a result; they are
  skipped, with the reason recorded.
- **Strip roles, scramble passwords and delete require a CSV export of that result** taken
  within the last six hours, and a result more than a day old must be rebuilt first.
- **Scramble and delete require typing the match count.** Delete also requires a decision
  about the accounts' content.
- **No email is sent** when passwords are scrambled.
- **Export files are deleted from the server after six hours.** The plugin keeps no copy of
  who was in a result; the file you download is that record.

## Licence

Licensed under [GPLv2 or later](LICENSE).
