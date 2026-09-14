# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-09-14

### Added

- **Tools → Purge User Accounts** admin screen with Build, Results, History, Settings and
  Help tabs, gated on the `delete_users` capability. The Help tab links to the published
  documentation.
- Query engine that stores each result as a run in custom tables, built by a chunked
  stepper driven over AJAX by the browser or by a loop under WP-CLI. A run takes a fixed
  user-ID ceiling at start, never loads user rows it does not need, and stops rather than
  returning a partial answer when a data source cannot be read. Criteria with incomplete
  arguments are refused before anything runs, and a criterion without a sense is always
  applied as its arguments describe.
- WP Core criteria: `wp-core.role`, `wp-core.any-role`, `wp-core.content`,
  `wp-core.comments`, `wp-core.registered`, `wp-core.bad-signup-pattern`,
  `wp-core.random-identity`, `wp-core.login-record` and `wp-core.active-since`. Every
  criterion except the registration date can be used as *has* or *has none*.
- WooCommerce criterion `woocommerce.orders`, supporting both the HPOS and legacy order
  stores, with generous default purchase statuses and a guest-order billing-email check
  that is on by default.
- Declared compatibility with WooCommerce's HPOS order tables (`custom_order_tables`).
- Bad-signup pattern engine matching a bundled ruleset (`data/bad-signup-rules.txt`)
  against a composed `ID,user_login,user_email` line. Rule comments become the reason
  shown in results; `#! default-off` ships rules inactive; rules can be disabled or enabled
  per site, and site rules added, through options.
- Email domain allowlist for the pattern criterion, with `*.` wildcards, dot-boundary
  matching, IDN normalisation and a per-entry count of matching users on save.
- Last-login recording on `wp_login` from activation, a coverage report that separates
  **Unknown** (registered before recording began) from **Never**, and a safe default on
  both login criteria that never selects the Unknown cohort. WooCommerce `wc_last_active`
  is available as an alternative activity source.
- Warning when criteria cannot overlap, such as the safe login criterion combined with a
  registration cutoff older than the login data.
- Results tab: paged table with a "Why matched" column and Unknown/Never badges, a
  random sample of 20, and a summary of match reasons.
- Streamed CSV export with roles, names, last-seen date and state, match reason, and
  extra user meta columns through `hwpua_csv_columns`.
- Actions: `force-logout`, `restore-roles`, `unblock-signin`, `revoke-app-passwords`,
  `strip-roles`, `block-signin`, `scramble-password` and `delete`, listed least destructive
  first. `strip-roles` skips accounts that already have no roles, so an earlier stash is
  never overwritten; `delete` requires an explicit choice about content, with nothing
  pre-selected.
- Pre-flight checklist computed per run and action: result freshness, export taken,
  protected accounts, minimum criteria, share of the site affected, WooCommerce order
  history and the content-reassignment decision. Dry run mode, and a "Block sign-in
  instead" option on the delete confirmation.
- Time estimates that switch from each action's declared rate to observed throughput once
  a job is under way.
- Saved queries (presets): save, load, forget, export and import as JSON from the Build
  tab, with validation against the receiving site.
- WP-CLI commands: `doctor`, `run`, `show`, `export`, `act`, `resume`, `stop`, `list-runs`,
  `list-presets`, `list-actions`, `preset-export`, `preset-import` and `preset-delete`.
  Jobs resume from their saved cursor, and `stop` is honoured before the next chunk by
  whichever process — browser or WP-CLI — is driving the job.
- Daily housekeeping on WP-Cron: expired exports are purged, runs are pruned after 30 days
  and estimates after one day, and jobs after 365 days. Runs with a pending, running or
  stopped job are kept.
- Uninstall removes the tables, every plugin option and the export files, and keeps the
  roles saved by `strip-roles`.
- Development commands `generate-fixture`, `destroy-fixture` and `benchmark`.
- Extension point `hwpua_register_integrations`, configuration filters, the `hwpua_error`
  action, and the `HWPUA_EXPORT_DIR` constant.

### Security

- Every AJAX endpoint and the export handler verify a nonce and re-check the
  `delete_users` capability. The server owns every cursor.
- The typed confirmation is verified on the server, not only in the browser.
- Guards re-applied to every chunk at execution time: the current user and the last
  administrator are always protected; accounts whose role holds `delete_users` are
  protected unless explicitly opted in. The administrator count is read directly rather
  than from a cache.
- Sign-in block refuses `authenticate`, `determine_current_user` and
  `allow_password_reset`, and ends sessions and application passwords when applied.
- Password scrambling uses `wp_set_password()`, which sends no email, and also revokes
  application passwords, which survive a password change.
- CSV cells beginning with `=`, `+`, `-` or `@` (including after leading whitespace) are
  neutralised against spreadsheet formula injection.
- Export files are written to an unguessable directory with unguessable names, deny rules
  and `index.php`, chmod `0600`, served only through the capability-checked handler, and
  deleted after six hours.
- Multisite networks are detected and the plugin stays inactive.
- Fixture generation is refused on a production environment unless
  `HWPUA_ALLOW_FIXTURES` is defined.
