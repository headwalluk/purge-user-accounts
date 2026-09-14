# Actions

What can be done to a completed run, how each action is guarded, and what can be undone.

Actions are listed on the Results tab under **Act on this result**, least destructive
first, and by `wp purge-users list-actions`.

## Summary

| ID | Label | Destructiveness | Reversible (as labelled) | Export required | Typed confirmation | Chunk | Starting rate |
|---|---|---|---|---|---|---|---|
| `force-logout` | Force logout | 5 | yes | no | no | 2,000 | 800/s |
| `restore-roles` | Restore stripped roles | 10 | yes | no | no | 1,000 | 400/s |
| `unblock-signin` | Allow sign-in again | 10 | yes | no | no | 1,000 | 500/s |
| `revoke-app-passwords` | Revoke application passwords | 15 | **no** | no | no | 1,000 | 500/s |
| `strip-roles` | Strip roles (quarantine) | 25 | yes | **yes** | no | 1,000 | 400/s |
| `block-signin` | Block sign-in | 30 | yes | no | no | 1,000 | 400/s |
| `scramble-password` | Scramble passwords | 60 | **no** | **yes** | **yes** | 200 | 26/s |
| `delete` | Delete accounts | 100 | **no** | **yes** | **yes** | 50 | 25/s |

*Destructiveness* orders the list and sets the friction: 50 or more adds the minimum-criteria
and share-of-site checks and requires typing the match count. *Starting rate* is used for
the estimate before a job is under way; see [Estimates](#estimates).

## What each action does

### `force-logout`

Destroys every session token for each user (`WP_Session_Tokens::destroy_all()`). Nothing
else changes, and the account can sign straight back in. Useful during an incident.

### `revoke-app-passwords`

Deletes every application password for each user. The login password is untouched and the
account can still sign in. Application passwords survive a password change, so this is the
action that ends REST API access by that route. Unavailable if the WordPress install has no
`WP_Application_Passwords` class. The owner can create new ones; the old ones cannot be
restored.

### `strip-roles`

For each user:

1. Saves the current role slugs to `hwpua_stashed_capabilities`, and the time to
   `hwpua_stashed_at`.
2. Removes every role with `WP_User::set_role( '' )`.
3. Destroys the user's sessions.

Capabilities granted directly to the user, outside a role, are left in place.

**This is not a lock-out.** A role-less account still authenticates. Plugin code that checks
only `is_user_logged_in()` and a nonce, without `current_user_can()`, stays reachable. Use
`block-signin` to stop an account being used.

An account that already has no roles is skipped with "Has no roles to strip. Any roles saved
by an earlier strip are kept." Stripping the same accounts again leaves the original stash in
place.

### `restore-roles`

For each user with a non-empty stash: removes all current roles, adds back each stashed
role, and deletes the stash meta. Users with nothing stashed are skipped with "No stashed
roles to restore." Any role given to the user since the strip is removed.

### `block-signin`

For each user: sets `hwpua_signin_blocked` and `hwpua_signin_blocked_at`, destroys every
session, and deletes every application password. The account, its roles and its data are
not changed.

While the plugin is active, a blocked account is refused at three points:

| Hook | Effect |
|---|---|
| `authenticate` (priority 100) | Username, email and application-password logins fail with "This account has been disabled by an administrator." |
| `determine_current_user` (priority 100) | An existing auth cookie, or one set directly by a social login plugin, does not resolve to the user |
| `allow_password_reset` (priority 100) | Password reset is refused |

Enforcement is at runtime: **deactivating or deleting the plugin lifts every block.**

### `unblock-signin`

Deletes the block meta. Users who were not blocked are skipped with "This account was not
blocked." Sessions and application passwords removed by the block are not restored.

### `scramble-password`

For each user: sets a random 64-character password with `wp_set_password()` and discards
it, destroys every session, and deletes every application password.

- **No email is sent.** `wp_set_password()` does not notify the user. `wp_update_user()`
  would, and is never used.
- The owner can set a new password through "Lost your password?". Anyone who can read the
  mailbox can too — and many disposable providers are public inboxes. Scrambling is not a
  lock-out.
- It does nothing against a social login that bypasses the password.
- bcrypt hashing is the cost: expect roughly 26 accounts a second.

### `delete`

Deletes each user with `wp_delete_user()`, so WordPress, WooCommerce and other plugins run
their own clean-up hooks. Raw SQL is never used. This is why deletion runs at tens of
accounts a second rather than thousands.

The job must carry `reassign_to`:

| Value | Effect |
|---|---|
| A user ID | The deleted accounts' posts are reassigned to that user |
| `0` | Their posts are deleted with them |

If `reassign_to` is absent, nothing is deleted and every user in the chunk is recorded as
failed: "No decision was recorded about what to do with their content, so nothing was
deleted." On the Results tab the choice — **Reassign it to me** or **Delete it along with the
accounts** — is shown when **Delete accounts** is selected, with neither pre-selected. Until
one is chosen, the pre-flight check blocks and the server refuses to create the job. On
WP-CLI `--reassign` must be given explicitly.

If WordPress returns false for a user — including one already deleted since the run was
built — that user is recorded as failed: "WordPress declined to delete this account."

## Before a job starts

### Result must be complete and fresh

A job can only be created for a run with status `complete` that finished within the last
day (`hwpua_run_stale_seconds`). This applies to every action. Rebuild the query to act on
an older result.

### Export

For `strip-roles`, `scramble-password` and `delete`, an export file for that run must exist
in the export directory. Export files are deleted after six hours, so in practice the export
must have been taken within the last six hours. The check applies to dry runs too.

### Pre-flight checklist

**Check this action** on the Results tab, and every `wp purge-users act`, builds a checklist.
Each line is computed for this run and action:

| Check | Applies to | Level |
|---|---|---|
| Result is complete and recent | All actions | **Blocks** if incomplete or more than a day old |
| Export taken | Actions requiring an export | **Blocks** if none on disk; otherwise shows its age |
| Protected accounts | All actions | Reports how many will be skipped, from the first 5,000 users of the run |
| Minimum criteria | Destructiveness ≥ 50 | Warns if the run rests on fewer than two criteria (`hwpua_minimum_criteria`) |
| Share of the site | Destructiveness ≥ 50 | Warns if the result is half or more of all users (`hwpua_proportion_warning`) — the circuit-breaker |
| WooCommerce order history | `delete`, WooCommerce active | Warns with the number of accounts linked to orders by customer ID |
| Content decision | `delete` | **Blocks** without `reassign_to`; warns when content will be deleted |

The checklist also shows the action's description, the match count and an estimated
duration. Nothing can start while any line blocks. The server rebuilds the checklist when
the job is requested, so a blocked result cannot be started by skipping the check.

### Typed confirmation

For `scramble-password` and `delete`, the operator types the run's match count, as plain
digits — for 38,801 matches, `38801`. A fixed word can be typed without reading it; the count
cannot. The server compares the typed value; the browser check is only a convenience. On
the Results tab this applies to dry runs as well. On WP-CLI the prompt is skipped for a dry
run and answered by `--yes`.

### The off-ramp

When the pre-flight is for `delete`, the confirmation offers **Block sign-in instead**, which
selects `block-signin` and re-runs the check.

## During a job

### Guards, re-applied to every chunk

Before each chunk is passed to the action, `Guards::find_protected()` examines those users'
capabilities afresh. A run built on one day and acted on the next may contain someone
promoted in between.

| Guard | Skip reason recorded | Overridable |
|---|---|---|
| The current user | This is your own account. | Never |
| IDs added through `hwpua_guarded_user_ids` | This user can manage other users. | Never |
| Users holding a role that has `delete_users` | This user can manage other users. | With `--allow-privileged` on WP-CLI; the admin screen has no opt-in |
| Administrators, when the chunk holds every administrator on the site | This is the last administrator on the site. | Never |

The administrator count is read directly from user meta for each chunk. If capabilities for a
chunk cannot be read, every user in it is protected.

At selection time only the current user and `hwpua_guarded_user_ids` are excluded.
Administrators and other privileged users can appear in a result, and are skipped when the
job runs.

Under WP-CLI without `--user`, there is no current user to protect.

### Dry run

Tick **Dry run** on the Results tab, or pass `--dry-run`. The job is created and stepped
normally, every guard runs and every skip is recorded, but the action itself is not called:
the remaining users are counted as succeeded. The job row keeps `dry_run` in `action_args`.

### What is recorded

Successes are counted on the job row. Every failure and every skip is written to
`hwpua_job_items` with its reason. See [database.md](database.md#hwpua_job_items).

### Estimates

Before a job starts, the estimate is the match count divided by the action's starting rate.
Once the job has processed at least 100 users over at least two seconds, the remaining time
uses its observed throughput since the job was created. A job that was paused and resumed
therefore reports a pessimistic estimate.

### Interruption

The cursor is saved after each chunk. Closing the browser tab pauses the job at its last
cursor. The admin screen has no stop or resume control in 1.0.0; continue the job with
`wp purge-users resume <job-id>`. A chunk interrupted part-way is processed again on resume:
for `delete`, accounts already removed are then recorded as failures.

`wp purge-users stop <job-id>` marks the job `cancelled`. Whatever is driving the job — a
browser tab or WP-CLI — checks the status before its next chunk, lets the chunk in hand
finish, and stops. `resume` sets the job running again. Only the first step changes a job
from `pending` to `running`; recording a chunk never changes the status, so a stop cannot be
overwritten by a chunk that was already in flight.

## Undoing actions

| Action | How to undo |
|---|---|
| `force-logout` | Nothing to undo |
| `block-signin` | `unblock-signin` on a run containing the accounts |
| `strip-roles` | `restore-roles` on a run containing the accounts |
| `revoke-app-passwords` | Cannot be undone; owners create new ones |
| `scramble-password` | Cannot be undone; owners reset by email |
| `delete` | **Cannot be undone.** The CSV export is the record of who was deleted |

Both undo actions need a fresh, complete run that contains the affected accounts. The
original run will usually be more than a day old. After `strip-roles`, the accounts no longer
hold their old role, so a query built on that role will not find them; `wp-core.any-role`
with *has none* will, and `restore-roles` skips anyone without a stash.

## Extending

Actions are supplied by integrations through `Integration::get_actions()`. All eight above
come from the WP Core integration. See [integrations.md](integrations.md#actions).
