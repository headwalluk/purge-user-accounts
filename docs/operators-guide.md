# Operator's guide

For site owners and administrators. **Read this before running anything destructive on a
site you care about.**

---

## What this plugin is for

Finding accounts that should not exist — automated signups, throwaway addresses, dormant
subscribers — and taking them out of use or removing them.

It is not a one-click cleaner. The review step is the product.

## Before you start

- **You need the `delete_users` capability.** On a standard site that means an
  administrator.
- **Single sites only.** On a multisite network the plugin stays inactive and says so.
- The screen is **Tools → Purge User Accounts**, with tabs for Build a query, Results,
  History, Settings and Help.
- If you have shell access, `wp purge-users doctor` reports what the plugin can see on this
  site — WooCommerce, the login data, the export directory. See [cli.md](cli.md).

## The single most important idea

Any one criterion on its own is weak. "Is a subscriber" describes most of your legitimate
members. "Has no content" describes most subscribers on most sites.

**Combining criteria is what makes a match meaningful.** A subscriber with no content, no
comments, no orders, no login record, registered over a year ago, with an address at a
known disposable-mail domain — that is a conclusion. Any one of those alone is a guess.

The pre-flight check warns when a destructive action rests on fewer than two criteria.

---

## The safe workflow

1. **Build a narrow query.** On the Build tab, tick more criteria than you think you need.
   Every criterion has *has none* and *has one or more*. Use **Estimate matches** to see
   the count before building.
2. **Run it and look at the count.** The Results tab shows "*N* users matched, of *M* on
   this site". If that is most of your users, the query is wrong, not your users.
3. **Read "Why they matched".** For pattern criteria, the summary panel groups the result
   by the rule that matched. Thousands of rows become a handful of claims you can check
   one at a time. If 12,000 users matched one rule, check that rule.
4. **Spot-check.** Click **Show 20 random matches**, and reload it several times. Do not
   judge from the first page: it is ordered by user ID, which is signup order, and it looks
   uniform whether or not the query is right. Open a few profiles.
5. **Download the CSV and open it.** Scan it. Keep it — once accounts are changed, it is
   the only record of who was affected.
6. **Block sign-in, or strip roles, before you ever delete.** Blocking is instant,
   reversible, and stops the accounts being used at all.
7. **Wait a fortnight.** If nobody complains and nothing breaks, build the query again,
   export again, and delete.

A result can only be acted on within a day of building it, and the destructive actions need
an export taken within the last six hours. Step 7 therefore always starts with a fresh
query and a fresh export.

---

## Last-login data

**This is where people get hurt. Read this section.**

WordPress records nothing about logins. The plugin starts recording every successful login
itself from the moment it is activated. Before that, it knows nothing.

So a user with **no login record** either:

- genuinely never logged in, **or**
- logged in regularly, before recording began

### Unknown and Never

The plugin separates these using registration dates:

| Label | Meaning |
|---|---|
| A date | The last recorded login |
| **Never** | No record, and the account was registered **after** recording began — the plugin was watching and saw nothing |
| **Unknown** | No record, and the account was registered **before** recording began — the plugin cannot say |

The Results table shows Unknown as a badge with an explanation; the CSV writes `Unknown` in
`last_seen` and `unknown` in `last_seen_state`, never an empty cell.

### Read the data-quality panel

Above the login criteria on the Build tab, the plugin shows the data source, its earliest
record, how many users have a record and how many do not, and — when there are any — how
many of those are **Unknown, not Never**.

### The rule

**Never delete users marked Unknown on the strength of login data alone.**

Both login criteria are safe by default: *has a login record* with *has none* and *seen
since* with *has none* **never return the Unknown cohort**. The box **Also include users
whose login history is unknown (riskier)** turns that protection off. Leave it unticked
unless the rest of the query stands on its own, and read the Unknown badges if you do tick
it.

### When a query returns nothing

The safe login criterion cannot return anyone registered before recording began. If you also
ask for accounts registered more than a year ago, and the plugin has been recording for less
than a year, the two sets cannot overlap and the result is empty. The Build tab warns about
this, naming both dates.

### It improves with time

On the day you activate the plugin, every existing account is Unknown and the login criteria
select nobody. After a year of recording, the login data is the most reliable signal on the
site. Until then, rely on the other criteria.

Logins are not recorded while the plugin is deactivated.

A site running WooCommerce can use WooCommerce's own "last active" timestamp instead. It
records **activity**, not logins — someone who stays signed in and browses counts as active
without logging in — and the Build tab says so when it is in use. It is selected with
`wp option update hwpua_last_login_source woocommerce-last-active`.

---

## Bad-signup patterns and the allowlist

The **Matches a bad-signup pattern** criterion compares each account's login and email with
a bundled list of rules. The Settings tab lists every rule with its description; rules that
ship switched off are shown unticked and marked "(off by default)".

**The bundled rules are a record of campaigns already seen**, not a general theory of what a
bot account looks like: disposable mail providers, SMS gateways, specific domain pools and
generated usernames that have turned up before. A site facing a campaign they have not seen
will not be protected by them, and will need its own rules. Rules that matched real people
during testing ship switched off.

### The email domain allowlist

Some legitimate addressing looks machine-made — universities that issue student numbers as
mailboxes, for example. Under Settings, list domains whose addresses are never treated as
pattern matches:

```
*.nhs.uk
ac.uk
```

An entry covers the domain and everything beneath it: `ac.uk` also covers
`student.gla.ac.uk`, but not `notac.uk`. When you save, the Settings tab shows how many of
your users each entry covers. If an entry covers a large share of the site, it is too broad.

The allowlist affects the pattern criterion only. It does not protect anyone from any other
criterion — if you want to keep customers, say so in the query with *Has WooCommerce orders:
has none*.

---

## Scrambling passwords is not a lock-out

**It locks out anyone holding the old password. It does not lock out anyone who controls the
mailbox.**

Many disposable mail providers are **public inboxes** — anyone who knows the address reads
the mail. A bot on one of them can click "Lost your password?", read the reset link and sign
back in. Scrambling also does nothing against a **social login** that bypasses the password.

Scrambling sends no email to the affected accounts.

## Stripping roles is not a lock-out either

A user with no role is still a **signed-in** user. Plugin code that checks only that someone
is logged in and holds a valid nonce, without checking capabilities, stays reachable:

```php
if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'something' ) ) { ... }
```

| Account | Roles | Can sign in? | Password reset? |
|---|---|---|---|
| Roles stripped | none | **yes** | **yes** |
| Sign-in blocked | unchanged | no | no |
| Untouched | unchanged | yes | yes |

## What achieves what

| Goal | Action |
|---|---|
| Stop an account being used at all, without deleting it | **Block sign-in** |
| End REST API access by application password | **Revoke application passwords** |
| Reduce what a legitimate account can do | **Strip roles** |
| Remove the account for good | **Delete accounts** |

**Block sign-in** refuses login, cookie sessions and password resets, ends existing sessions
and revokes application passwords. It changes no account data, and **Allow sign-in again**
lifts it. It is enforced only while the plugin is active — deactivating the plugin lifts
every block.

The cautious path is: **block sign-in → wait a fortnight → delete.**

See [actions.md](actions.md) for exactly what each action changes.

---

## Reading the pre-flight check

Select an action on the Results tab and click **Check this action**. Every line is worked out
for this result:

| Line | What to do |
|---|---|
| **This result is more than a day old** | Build the query again |
| **Download the CSV first** | Download it; the destructive actions will not start without it |
| **N protected accounts will be skipped** | Expected when administrators or your own account match; they are never touched |
| **This result rests on only N criteria** | Add criteria before destroying anything |
| **This is N% of every account on the site** | The circuit-breaker. Correct for a site overrun with signups; otherwise the query is too broad |
| **N of these accounts have WooCommerce order history** | Deleting them leaves those orders without a customer. Usually a reason to add *Has WooCommerce orders: has none* |
| **Choose what happens to any content these accounts authored** | Delete only: pick **Reassign it to me** or **Delete it along with the accounts**. Neither is pre-selected, and nothing starts until you choose |

**Scramble passwords** and **Delete accounts** then ask you to type the number of matched
accounts, as digits. For delete, a **Block sign-in instead** button sits beside it.

Tick **Dry run** to run every check and guard and report what would happen, without changing
anything.

### Accounts the plugin will not touch

Whatever the query returns, a job always skips your own account and the last administrator.
It also skips anyone whose role can delete users, unless a WP-CLI operator passes
`--allow-privileged`. These checks run again as each batch is processed, so someone promoted
after the query was built is still protected. Protected accounts can appear in the results
table; they are skipped, with the reason recorded.

---

## What cannot be undone

| Action | Undo |
|---|---|
| Force logout | Nothing to undo |
| Block sign-in | **Allow sign-in again** |
| Strip roles | **Restore stripped roles** |
| Revoke application passwords | No — owners create new ones |
| Scramble passwords | No — owners reset by email |
| **Delete accounts** | **No.** Nothing in the plugin can restore a deleted account |

To undo a block or a strip after a result has gone stale, build a query that finds those
accounts again. After stripping, the accounts have no role, so a query on their old role will
not find them — use **Has any role: has none**. **Restore stripped roles** skips accounts that
have nothing saved.

Stripping roles skips accounts that already have none, so running it again on the same
accounts keeps the roles saved by the first strip.

## Exports

- The CSV holds user ID, login, first and last name, display name, email, roles,
  registration date, last seen and its state, and why the account matched.
- **The copy on the server is deleted after six hours.** The plugin keeps no durable copy of
  who was in a result. The file you download is the record — keep it somewhere safe.
- Files on the server are owner-readable only, in a randomly named directory, and can only
  be downloaded through the plugin by a user with `delete_users`. By default the directory is
  inside `wp-content`; your host can move it outside the web root with `HWPUA_EXPORT_DIR`
  (see [hooks.md](hooks.md#constants)). The Settings tab shows where it is.
- Cells that a spreadsheet would treat as a formula are prefixed with `'`, so a hostile
  display name cannot run in your spreadsheet.

## What the plugin keeps

- **Runs** — the criteria and the matching user IDs — and **jobs** — which action ran on
  which run, when, by whom, the counts, and every individual failure and skip with its
  reason. The job record does not include email addresses or names.
- Runs are deleted 30 days after they were built, and estimates after a day, unless a job on
  the run is still paused or stopped. Job records are deleted after twelve months. This
  housekeeping runs on WP-Cron, so on a site with very little traffic it happens late. The
  History tab lists runs only; job records are in the database. See
  [database.md](database.md#retention).

---

## WooCommerce

**Leave "Also count guest orders matched by email address" ticked.** A customer who ordered
as a guest before registering is linked to that order only by email address. Without the
check, they look like they have never purchased.

The default purchase statuses are generous: processing, completed, on-hold and refunded all
count. Sparing a junk account costs nothing; deleting a real customer costs a complaint and an
orphaned order.

## Other things the plugin cannot see

Be careful before trusting a "has nothing attached" result:

- **Content outside posts and comments.** Forum posts, course progress, form entries and
  reviews in their own tables are invisible to the content and comment criteria.
- **Custom post types.** From the Build tab, content means posts and pages only.
- **Memberships keyed on something other than the role.** A role-less user may hold a paid
  membership.

If your site runs a plugin that stores user data its own way, combine criteria and spot-check
harder.

---

## If something goes wrong

- **A query was interrupted.** Closing the tab stops a query. Run it again from the Build tab.
- **A job was interrupted.** Closing the tab pauses a job. Nothing done so far is lost; the
  job remembers where it got to. Continue it from the shell with
  `wp purge-users resume <job-id>` — the admin screen has no stop or resume button in 1.0.0.
- **You need to halt a job.** From the shell, run `wp purge-users stop <job-id>`. Whatever is
  driving the job, browser or WP-CLI, finishes the batch in hand and stops.
- **You blocked accounts you should not have.** Build a query that finds them and apply
  **Allow sign-in again**.
- **You stripped roles and something broke.** Build a query that finds them (**Has any role:
  has none**) and apply **Restore stripped roles**.
- **You deleted accounts you should not have.** The CSV you downloaded before the job holds
  their email addresses, roles and names. It is not an undo, but it tells you exactly who was
  affected so you can contact them.

---

## Questions worth asking before you start

- When was this plugin activated? Everyone registered before then is Unknown to the login
  criteria.
- Do I run a membership, LMS or forum plugin that stores data its own way?
- Have I ever taken guest orders?
- Which domains do my real users' institutions use?
- What is the oldest account I would be genuinely sorry to lose?
