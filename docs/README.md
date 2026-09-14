# Documentation

Reference documentation for Purge User Accounts 1.0.0.

## For site operators

| Document | Covers |
|---|---|
| [operators-guide.md](operators-guide.md) | The safe workflow, last-login data, what cannot be undone |
| [filters.md](filters.md) | Every selection criterion and its exact semantics |
| [actions.md](actions.md) | What each action changes, its guards and its reversibility |
| [cli.md](cli.md) | WP-CLI commands, presets and worked examples |

The plugin's **Help** tab, under **Tools → Purge User Accounts**, summarises the safe order of
work and links to these four documents.

## For developers

| Document | Covers |
|---|---|
| [architecture.md](architecture.md) | File layout, bootstrap, classes, runs, jobs and the stepper |
| [integrations.md](integrations.md) | The integration contract — how criteria, actions and login sources are supplied |
| [hooks.md](hooks.md) | Hooks consumed and emitted, and definable constants |
| [database.md](database.md) | Custom tables, options, user meta, retention and uninstall |

## Vocabulary

| Term | Meaning |
|---|---|
| **Criterion** / **filter** | One selection condition, such as `woocommerce.orders` |
| **Sense** | `has` or `has_not` — whether a criterion selects users with or without the condition |
| **Run** | One execution of a set of criteria, stored as a list of user IDs |
| **Job** | One action applied to one run |
| **Integration** | The code that supplies criteria for one system — WP Core, WooCommerce |
| **Preset** | A saved, named set of criteria; stores the question, not the answer |
| **Unknown** | A user with no login record who registered before the login data begins |

## Not in 1.0.0

- LearnDash integration (planned for 1.1)
- A registration-cluster criterion, and WooCommerce Subscriptions or Memberships criteria
- Editing per-rule toggles, site rules or the last-login source from the Settings screen —
  these are options, set with WP-CLI (see [filters.md](filters.md))
- Resuming an interrupted build, or stopping or resuming a job, from the admin screen —
  jobs are stopped and resumed with `wp purge-users stop` and `wp purge-users resume`
- A screen listing jobs
