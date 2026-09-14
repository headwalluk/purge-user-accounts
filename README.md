# Purge User Accounts

![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-21759B?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-optional-7F54B3?logo=woocommerce&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv2%20or%20later-blue)
![Status](https://img.shields.io/badge/status-pre--release-orange)

Find, audit and remove junk user accounts on WordPress sites — at a scale where the
usual tools give up.

Built by [Headwall Hosting](https://headwall-hosting.com) after years of clearing botnet
signups off client sites by hand with SQL and WP-CLI.

## The problem

Automated signup abuse leaves WordPress sites carrying tens of thousands of dormant
accounts: throwaway mailboxes, generated usernames, subscribers who have never logged in
and never will. They bloat the database, poison the site's mailing reputation, and every
one of them is a credential waiting to be stuffed.

The built-in Users screen cannot help. It has no concept of "never logged in", no way to
combine criteria, and its bulk actions collapse well before 100,000 rows.

## What this plugin does

1. **Build a query** by ticking criteria — roles, content, comments, purchases, login
   recency, email reputation. Criteria combine, and combining them is the point: a
   subscriber with no content, no orders and a throwaway address is a very different
   proposition from a subscriber.
2. **Run it** and get a total plus a paged, sortable table of who matched and *why*.
3. **Export to CSV** with user metadata, before touching anything.
4. **Act on the result** — strip roles, force logout, scramble passwords, or delete —
   with the destructive options gated behind confirmation proportional to the damage.

Results are held server-side, so paging, exporting and acting all address the same
stable, auditable set rather than re-running a query that shifts underneath you.

## Documentation

The `docs/` directory is the entry point for anyone picking this project up:

- [docs/architecture.md](docs/architecture.md) — plugin structure, classes, conventions
- [docs/integrations.md](docs/integrations.md) — the integration contract, and what ships
- [docs/database.md](docs/database.md) — custom tables, schema, retention
- [docs/filters.md](docs/filters.md) — every selection criterion and its exact semantics
- [docs/actions.md](docs/actions.md) — what each bulk action does, and what it cannot undo
- [docs/hooks.md](docs/hooks.md) — action/filter hook reference for developers
- [docs/cli.md](docs/cli.md) — WP-CLI commands and preset usage
- [docs/operators-guide.md](docs/operators-guide.md) — for site owners: how to use this safely

## Safety

This plugin deletes people. Read [docs/operators-guide.md](docs/operators-guide.md) before
running anything destructive on a site you care about — particularly the section on
last-login data, which is the single most common source of false positives.

Administrators are excluded from selection by default. Every destructive action requires
an export first. Nothing is deleted without an explicit, typed confirmation.

## License

Licensed under [GPLv2 or later](LICENSE).
