# Operator's guide

> **Status: pre-release.** Completed at Milestone 13.

For site owners and administrators. **Read this before running anything destructive on a
site you care about.**

---

## What this plugin is for

Finding accounts that should not exist — automated signups, throwaway addresses, dormant
subscribers — and removing them safely.

It is not a one-click cleaner. The review step is the product.

## The single most important idea

Any one criterion on its own is weak. "Is a subscriber" describes most of your legitimate
members. "Has no content" describes most subscribers on most sites.

**Combining criteria is what makes a match meaningful.** A subscriber with no content, no
comments, no orders, no login record, registered over a year ago, with an address at a
known disposable-mail domain — that is a conclusion. Any one of those alone is a guess.

## The safe workflow

1. **Start narrow.** Tick more criteria than you think you need. Widen only if the result
   is too small to be useful.
2. **Run it and look at the count.** If it is 95% of your users, something is wrong with
   the query, not with your users.
3. **Sort by "Why matched".** This groups thousands of rows into a handful of reasons. If
   12,000 users matched one pattern, that pattern is worth checking.
4. **Spot-check.** Use **Show 20 random matches**, several times. Do not just read the
   first page — it is ordered by ID, which means ordered by signup date, which means it
   looks uniform whether or not the query is right.
5. **Export the CSV and open it.** Scan it. This is your only record afterwards.
6. **Block sign-in rather than deleting.** Instant, reversible, and it stops the accounts
   being used at all — see the warning below about why stripping roles is not equivalent.
7. **Wait a fortnight.** If nobody complains and nothing breaks, delete.

That last pair is the difference between a confident purge and an expensive mistake.

---

## Last-login data

**This is where people get hurt. Read this section.**

WordPress records nothing about logins. It has no `last_login` field and no history. Any
login data this plugin shows comes from another plugin that happened to be installed and
happened to be recording.

So when the plugin says a user has **no login record**, that can mean either:

- they genuinely never logged in, **or**
- they logged in regularly for years, before the data source started recording

The plugin cannot always tell these apart — but it always tells you which it is.

### Read the data-quality panel

Above the login criteria you will see something like:

> Source: WooCommerce "last active" · Earliest record: 11 March 2024
> 41,203 users have no record — **of which 38,908 registered before March 2024**

Those 38,908 are **Unknown**, not "never logged in". They appear in the table with an
Unknown badge and in the CSV as the literal word `unknown`.

### The rule

**Never delete users marked Unknown on the strength of login data alone.** Either add
other criteria that stand on their own, or use the safe variant of the filter, which only
matches users who registered *after* tracking began.

### It gets better

The plugin starts recording logins itself the day you activate it. After a year, it is
the most reliable source on the site. If you have just installed it, lean on the other
criteria for now.

---

## Scrambling passwords is not a lock-out ⚠

If you are nervous about deleting, the obvious plan is to scramble passwords and revoke
application passwords instead. That is a reasonable instinct — but be clear about what it
does and does not achieve.

**It locks out anyone holding the old password. It does not lock out anyone who controls
the mailbox.**

Many disposable mail providers are **public inboxes** — mailinator, yopmail, guerrillamail,
sharklasers and most of the others this plugin's rules look for. Anyone who knows the
address reads the mail without a password; that is their entire purpose. So a bot on
`something@mailinator.com` can:

1. Click "Lost your password?"
2. Read the reset link in the public inbox
3. Set a new password
4. Sign back in

Scrambling also does nothing against a **social login**. If your site runs Nextend,
WooCommerce Social Login or similar, those accounts sign in without a password at all.

### Stripping roles is not a lock-out either ⚠

It is tempting to think a user with no role can do nothing. That is only true if every
plugin on your site checks *capabilities*. A lot of plugin code checks only that someone is
logged in and holds a valid nonce:

```php
if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'something' ) ) { ... }
```

A role-less account is still a **logged-in** account, so it walks straight through code
like that. This is a recurring vulnerability class, not a hypothetical. Verified directly:

| Account | Roles | Can sign in? | Password reset? |
|---|---|---|---|
| Roles stripped | none | **yes** | **yes** |
| Sign-in blocked | subscriber | no | no |
| Untouched | subscriber | yes | yes |

### What actually achieves what

| Goal | What achieves it |
|---|---|
| Stop an account being used at all, without deleting it | **Block sign-in** |
| Stop API abuse immediately | **Revoke application passwords** |
| Reduce what a legitimate account can do | **Strip roles** |
| Remove the account for good | **Delete** |

**Block sign-in is the cautious option**, not strip roles and not scrambling. It refuses
authentication outright, ends existing sessions, revokes application passwords and blocks
password resets — so neither a stolen password, a public inbox, nor a social login gets
back in. It changes no data and is lifted with one click.

The recommended cautious path is: **block sign-in → wait a fortnight → delete.**

## Other things the plugin cannot see

Be aware of these before trusting a "has nothing attached" result:

- **Content in custom tables.** Forum posts, LMS progress, form entries and similar live
  outside `wp_posts` and are invisible to the content filter.
- **Memberships keyed on something other than the role.** A user who looks role-less may
  hold a paid membership.
- **Reviews stored outside `wp_comments`.**

If your site runs a plugin that stores user-attached data its own way, the content and
comment filters will not account for it. Combine with login and purchase criteria, and
spot-check harder.

---

## WooCommerce

**Leave "also spare users whose email appears on a guest order" switched on.** A customer
who ordered as a guest before registering is not linked to that order by ID — only by
email address. Without this check they look like they have never purchased.

The purchase-status defaults are deliberately generous: a refunded order still counts as
a purchase. Sparing a junk account costs you nothing; deleting a real customer costs you
a complaint and an orphaned order.

---

## If something goes wrong

- **A job stopped partway.** Nothing is lost. Open the History tab and resume it.
- **You deleted users you should not have.** The CSV you downloaded before the job is your
  record — it holds their email addresses, roles and metadata. It is not an undo, and
  accounts cannot be restored faithfully, but it tells you exactly who was affected so you
  can contact them.

  **Keep that file somewhere safe.** The plugin deletes its own copy from the server after
  six hours, deliberately — a spreadsheet of tens of thousands of email addresses sitting on
  a web server indefinitely is a liability. What the plugin retains is the *job record*:
  what was run, when, by whom, how many succeeded and every individual failure with its
  reason. That is kept for twelve months. It does not include the affected people's details.
- **You stripped roles and something broke.** Use **Restore stripped roles** on that job.
  This is fully reversible, which is why it is the recommended first step.

---

## Questions worth asking before you start

- When was my security or analytics plugin installed? (That is the earliest login record.)
- Do I run a membership, LMS or forum plugin that stores data its own way?
- Have I ever taken guest orders?
- What is the oldest account I would be genuinely sorry to lose?

If you cannot answer the first one, do not use the login criteria yet.
