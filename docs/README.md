# Documentation

Reference documentation for Purge User Accounts.

> **Status: pre-release.** The plugin is not yet implemented. These documents describe the
> intended behaviour and are finalised as each part is built. Where a page is thin, the
> design detail behind it is still under review.

## For developers

| Document | Covers |
|---|---|
| [architecture.md](architecture.md) | Plugin structure, classes, the run/job model |
| [integrations.md](integrations.md) | The integration contract — how criteria are supplied |
| [database.md](database.md) | Custom tables, schema, retention |
| [filters.md](filters.md) | Selection criteria and their exact semantics |
| [actions.md](actions.md) | Bulk actions, guards, reversibility |
| [hooks.md](hooks.md) | Action and filter hooks consumed and emitted |
| [cli.md](cli.md) | WP-CLI commands and presets |

## For site operators

| Document | Covers |
|---|---|
| [operators-guide.md](operators-guide.md) | How to use this without deleting a real customer |

Operator help is also available inside the plugin, under **Tools → Purge User Accounts →
Help**.
