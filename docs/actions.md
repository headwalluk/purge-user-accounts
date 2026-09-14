# Actions

> **Status: pre-release.** Completed at Milestone 10.

Operations that can be applied to a completed run.

## The reversibility ladder

| Action | Cost at 40,000 users | Reversible | Confirmation |
|---|---|---|---|
| Force logout | seconds | n/a | One click |
| Restore stripped roles | seconds | n/a | One click |
| Revoke application passwords | seconds | No — owner can recreate | Click + checkbox |
| **Strip roles** | seconds | **Fully** | Click + checkbox |
| Scramble passwords | ~26 minutes | Owner can reset | Typed confirmation |
| Delete | minutes to hours | **No** | Type the match count |

> **Scrambling is not a lock-out.** Most disposable mail providers are public inboxes, so a
> bot can request a password reset and read it. See
> [operators-guide.md](operators-guide.md) — strip roles is the better cautious option.

## Strip roles is the one to reach for

The instinctive workflow is *select, then delete*. The better one is:

> **Strip roles → wait a fortnight → delete.**

Role stripping neutralises an account completely — no login privileges, no REST access,
no comment posting — in one statement's work per user, and it is fully reversible because
the original capabilities are stashed first. If something breaks, restore. If nothing
breaks in two weeks, delete with confidence.

It also closes a door that a password change does not: an account with no capabilities
gains nothing from a social login that bypasses passwords entirely.

## Scramble password

Uses `wp_set_password()`, which is silent. It also deletes the user's Application
Passwords — which **survive a password change** and would otherwise leave a bot with full
REST API access — and clears their session tokens.

Bcrypt costs roughly 38 ms per user on typical hardware, so this action runs at about
26 users/sec and cannot be made faster without weakening the hash. The plugin shows a
time estimate before you commit.

It does not help against social or OAuth logins, which bypass the password entirely.

## Delete

Uses `wp_delete_user()`, never direct SQL, so that WooCommerce and every other plugin get
their cleanup hooks. That is why deletion runs at tens per second rather than thousands.

The reassign-or-delete choice for their content is always explicit, never a silent
default.

### Guards

| Guard | Overridable |
|---|---|
| The current user | Never |
| The last remaining administrator | Never |
| Holders of `delete_users` | Yes, with separate confirmation |

Guards are re-applied to each chunk as it is processed, not just at selection — so a user
promoted to administrator between building a run and executing it is still caught.

Every skip and failure is recorded with a reason.

## Extending

Actions are supplied by [integrations](integrations.md), via
`Integration::get_actions()`. All four actions above come from the WP Core integration.
