# Filters

Selection criteria, as registered by the code in 1.0.0.

## How criteria combine

A query is a list of criteria. **All criteria combine with AND.** Each carries a sense:

| Sense | Build tab label | Selects users who… |
|---|---|---|
| `has` | *has one or more* | meet the criterion's positive condition |
| `has_not` | *has none* (the default) | do not |

Every criterion ID names the **positive** condition. "Has never purchased" is
`woocommerce.orders` with `has_not`; "is a customer" is the same criterion with `has`.

In a preset or WP-CLI specification each criterion is `{ "id", "sense", "args" }`. A
missing `sense` is treated as `has_not`; missing `args` is `{}`. A criterion that has no
sense — `wp-core.registered` — is always applied as `has`, whatever sense was sent, because
its direction is in its arguments.

A criterion whose arguments cannot express a usable condition is refused before anything
runs, with a message such as *The criterion "Role" is incomplete: tick at least one role.*
This applies to `wp-core.role`, `wp-core.content`, `wp-core.registered` and
`wp-core.active-since`, when a query is estimated or run and when a preset is saved or
imported.

A criterion whose integration is inactive is not registered at all. The Build tab shows the
integration with its reason ("WooCommerce is not active on this site."), and a
specification naming it is refused rather than run without it.

## Summary

| ID | Build tab label | Group | Kind | Sense | Arguments | Available when |
|---|---|---|---|---|---|---|
| `wp-core.role` | Role | Roles | SQL | yes | `roles` | Always |
| `wp-core.any-role` | Has any role | Roles | SQL | yes | — | Always |
| `wp-core.content` | Has published content | Content and comments | SQL | yes | `post_types`, `post_statuses` | Always |
| `wp-core.comments` | Has comments | Content and comments | SQL + scratch | yes | — | Always |
| `woocommerce.orders` | Has WooCommerce orders | Purchases | SQL + scratch | yes | `statuses`, `include_guest_email` | WooCommerce active |
| `wp-core.login-record` | Has a login record | Login activity | SQL | yes | `include_unknown` | A last-login source exists |
| `wp-core.active-since` | Seen since | Login activity | SQL | yes | `days_ago` or `date`, `include_unknown` | A last-login source exists |
| `wp-core.bad-signup-pattern` | Matches a bad-signup pattern | Email and username patterns | Refine | yes | `use_allowlist` | Always |
| `wp-core.random-identity` | Username or email looks machine-generated | Email and username patterns | Refine | yes | — | Always |
| `wp-core.registered` | Registration date | Registration date | SQL | no — always `has` | `direction`, `days_ago` or `date` | Always |

The native login source always exists once the plugin is active, so the two login criteria
are always available in 1.0.0.

**SQL** criteria are folded into the seed statement. **Scratch** criteria first
materialise a set of user IDs once per run. **Refine** criteria are evaluated in PHP over
chunks of 10,000 stored results. See [architecture.md](architecture.md#runs).

---

## WP Core

### `wp-core.role`

Holds at least one of the named roles.

| Argument | Type | Default |
|---|---|---|
| `roles` | array of role slugs | none |

Matches when the user's `{prefix}capabilities` meta contains any of the slugs in double
quotes, so `subscriber` does not match a custom `subscriber_plus` role. On the Build tab the
roles are ticked individually.

With no roles named, the criterion is refused as incomplete: "tick at least one role."

Users with no capabilities row are invisible to this criterion in both senses; use
`wp-core.any-role`.

### `wp-core.any-role`

Holds any role at all. With `has_not`, finds users whose capabilities meta is missing,
empty or `a:0:{}` — common among bulk bot signups and missed by role-based tools.

Any other non-empty capabilities value counts as having a role, including one that grants
only individual capabilities.

### `wp-core.content`

Has authored posts of the given types and statuses.

| Argument | Type | Default |
|---|---|---|
| `post_types` | array | `post`, `page` |
| `post_statuses` | array | `publish`, `future`, `draft`, `pending`, `private` |

Despite the label, drafts, pending, scheduled and private posts count. `auto-draft` is not
in the default statuses — opening the new-post screen once does not spare an account.
`revision` is removed from the post types whatever is passed. Attachments are not counted
unless `attachment` is listed. If no post type or no status is left, the criterion is
refused as incomplete: "choose at least one post type and one status."

The Build tab has no control for these arguments, so custom post types (products, forum
topics, courses) do not count unless named in a preset.

### `wp-core.comments`

Has left at least one comment, as a registered user, that is not marked spam or trash.
Pending and approved comments both count. Comments left without logging in are linked by
name and email, not user ID, and are not counted.

`wp_comments` has no index on `user_id`, so the commenter set is materialised once per run
into scratch bucket `commenter`.

### `wp-core.registered`

Registered before or after a cutoff.

| Argument | Type | Default |
|---|---|---|
| `direction` | `before` or `after` | `before` (anything other than `after`) |
| `days_ago` | integer | — (takes precedence over `date`) |
| `date` | date string, read as UTC | — |

The comparison is strict: `user_registered < cutoff` for `before`, `> cutoff` for `after`.
Without a usable `days_ago` or `date`, the criterion is refused as incomplete: "give a
number of days or a date."

The criterion has no sense. Whatever sense a specification carries — including `has_not`
stored in an older preset — it is applied as `has`, so the arguments alone decide the
direction: `direction: before` with `days_ago: 365` selects accounts registered more than
365 days ago.

The Build tab offers "more than *N* days ago" (`direction: before`, default 365). It is
mostly a safety criterion: it keeps a recent legitimate signup, who has not yet had time to
log in, comment or buy, out of the result.

### `wp-core.bad-signup-pattern`

The user's `ID,user_login,user_email` line matches an active bad-signup rule. See
[The bad-signup pattern engine](#the-bad-signup-pattern-engine).

| Argument | Type | Default |
|---|---|---|
| `use_allowlist` | boolean | `true` ("Ignore allowlisted domains", ticked) |

With the allowlist in use, an address on an allowlisted domain is treated as matching no
rule. Under `has` that removes the user from the result; under `has_not` it keeps them.

With `has`, the label of the rule that matched is stored as the user's **Why matched**
reason. With `has_not` no reason is recorded.

### `wp-core.random-identity`

The login or the email local part looks machine-generated. Structural, not list-based, so
it can catch identifiers no rule has seen.

Each value has non-alphanumeric characters removed and is judged generated if any of these
holds:

| Test | Example shape |
|---|---|
| Letters only, at least 5 long, with three or more lower-to-upper case changes | `CwAmFdRDNWyM` |
| At least 18 characters, letters and digits, digits at least 35% | long mixed identifiers |
| At least 16 characters with 12 or more letters and no vowel (`aeiouy`) | consonant runs |
| Two or more repeats of 1–3 letters followed by 4+ digits | `ab1234cd5678` |

A single CamelCase name (`JonGill`) has one case change and does not match. A user
matches when either the login or the local part matches. No reason is recorded.

### `wp-core.login-record`

The active last-login source holds a record for the user. With `has_not`, "has no login
record" — **not** "never logged in". See [Last-login data](#last-login-data).

| Argument | Type | Default |
|---|---|---|
| `include_unknown` | boolean | `false` |

| Sense | `include_unknown: false` (safe) | `include_unknown: true` |
|---|---|---|
| `has` | Has a record, **or** registered before the source began | Has a record |
| `has_not` | No record **and** registered on or after the source began | No record, whenever registered |

The safe variant counts the Unknown cohort as having a record, so `has_not` never returns
them. If the source has no start date at all, the safe variant treats every user as having
a record: `has_not` returns nobody.

On the Build tab the opt-in is "Also include users whose login history is unknown
(riskier)".

### `wp-core.active-since`

The active source holds a record on or after a cutoff. With `has_not`, "not seen within the
last *N* days".

| Argument | Type | Default |
|---|---|---|
| `days_ago` | integer | Build tab default 365 (takes precedence over `date`) |
| `date` | date string, read as UTC | — |
| `include_unknown` | boolean | `false` |

The Unknown cohort is handled exactly as for `wp-core.login-record`. Without a usable
cutoff the criterion is refused as incomplete: "give a number of days or a date."

When the source is WooCommerce's `wc_last_active`, a record means the user was *seen* while
signed in, not that they logged in. The Build tab says so.

---

## WooCommerce

### `woocommerce.orders`

Has one or more qualifying orders.

| Argument | Type | Default |
|---|---|---|
| `statuses` | array, with or without the `wc-` prefix | WooCommerce's paid statuses (`processing`, `completed`) plus `on-hold` and `refunded` |
| `include_guest_email` | boolean | `true` ("Also count guest orders matched by email address", ticked) |

Only orders of type `shop_order` count. The default statuses are wider than WooCommerce's
own paid list on purpose: a refunded order still means the person bought something.
Pending, failed, cancelled and checkout-draft orders do not count. The Build tab has no
status control; use a preset to change the list.

The customer set is materialised once per run into scratch bucket `wc_customer`:

| Pass | HPOS store | Legacy store |
|---|---|---|
| By customer | `wc_orders.customer_id > 0` | `_customer_user` postmeta `> 0` |
| By email (when `include_guest_email`) | `wc_orders.billing_email = user_email` | `_billing_email` postmeta `= user_email` |

The store is detected with `OrderUtil::custom_orders_table_usage_is_enabled()`.

**Leave the guest-email check on.** A customer who ordered as a guest before registering
has `customer_id = 0` on that order and is linked only by address. Without the check they
look like they have never purchased.

---

## Pitfalls

### Unknown is not Never

WordPress records no logins. A user with no record either never logged in, or logged in
before the source began recording. The plugin calls the second group **Unknown**: no record
**and** registered before the source's earliest record. The Results table shows an Unknown
badge; the CSV writes `Unknown` in `last_seen` and `unknown` in `last_seen_state`.

Keep `include_unknown` off unless other criteria in the query stand on their own, and read
the Unknown badges before acting.

### Criteria that cannot overlap

The safe login variant never returns anyone registered before the login data begins. Add
"registered more than a year ago" on a site whose login data is younger than a year, and
the two sets cannot overlap: the query returns nothing, correctly.

When a safe `has_not` login criterion is combined with a `before` registration cutoff that
is earlier than the source's earliest record, **Estimate matches** and **Run query** show a
warning naming both dates. WP-CLI does not print this warning.

### What the content and comment criteria cannot see

Forum posts, course progress, form entries, reviews and memberships held in other tables
are invisible to `wp-core.content` and `wp-core.comments`. A user with nothing attached
here may still be an active member elsewhere on the site.

---

## Last-login data

### Sources in 1.0.0

| Source ID | Label | Priority | Meta key | Format | Records |
|---|---|---|---|---|---|
| `hwpua-native` | Purge User Accounts (this plugin) | 10 | `hwpua_last_login` | UTC datetime | Logins, from activation |
| `woocommerce-last-active` | WooCommerce last active | 20 | `wc_last_active` | Unix timestamp | Activity by signed-in users |

The source with the lowest priority number is used unless option
`hwpua_last_login_source` names another. Sources are never combined. The native source is
always present, so it is used by default:

```bash
wp option update hwpua_last_login_source woocommerce-last-active
```

### Coverage

For the active source, the plugin computes users with a record, users without one, the
**earliest record**, and the **Unknown cohort**. The earliest record is the older of the
oldest stored value and the source's own start date — for the native source, the
activation time in `hwpua_activated_at`. The Build tab shows these above the login
criteria; `wp purge-users doctor` prints them.

In the Results table and CSV, a timestamp more than a day in the future is treated as no
record. The SQL criteria do not apply that check in 1.0.0, so a corrupt future value counts
as a recent login. For a Unix-timestamp source such as `wc_last_active`, `FROM_UNIXTIME()`
converts in the database session's time zone while cutoffs are UTC, so a comparison can be
out by the server's offset.

Logins are not recorded while the plugin is deactivated. Re-activation updates
`hwpua_activated_at`.

---

## The bad-signup pattern engine

### The composed line

Each rule is a regular expression matched against one line per user:

```
ID,user_login,user_email
```

for example `4028,user_x7k2p9q1,someone@example.com`. Rules are written against that shape:

| Anchor | Targets |
|---|---|
| `^[0-9]+,` | The login field |
| Leading `,` and trailing `[^,]*$` | The email field only |
| Bare `$` | End of line — the email |

A login containing a comma shifts the field boundaries; rules inherit that behaviour.

Patterns are compiled as `~pattern~i` — case-insensitive, no `u` flag. Rules are tested in
order, bundled rules first and site rules after, and **the first match wins**.

### File format

```
# Disposable / throwaway mail providers.
# Subdomain-tolerant.
@([a-z0-9-]+\.)*(mailinator|yopmail)\.[a-z]+$

#! default-off
#
# Local part is a long digit run with a short letter tail.
,[0-9]{6,}[a-z]{1,4}@[^,]*$
```

- Lines are trimmed. A blank line ends the current comment block.
- A line starting with `#` is a comment. Its text (with leading `#` characters removed)
  joins the comment block. Lines of only `#` add nothing.
- Any other line is a rule. It takes the current comment block as its **description**, and
  the first comment line that does not start with `^` or `\` — at most 120 characters — as
  its **label**. That label is the **Why matched** reason. A rule with no comment block is
  labelled with its own pattern.
- The comment block is **not** cleared after a rule, so one comment describes every rule
  beneath it up to the next blank line.
- `#! default-off` marks every rule after it, up to the next blank line, as shipped but
  inactive.
- A pattern that is not valid PCRE is skipped and named in an error notice on the plugin
  screen.

### Rule keys, enabling and disabling

Each rule's key is the first 16 hex characters of the SHA-256 of its trimmed pattern text.
Changing a pattern changes its key.

| Option | Effect |
|---|---|
| `hwpua_disabled_rules` | Array of keys that never match |
| `hwpua_enabled_rules` | Array of keys that match even though they ship `#! default-off` |
| `hwpua_custom_rules` | Site rules in the same text format, tested after the bundled rules |

The Settings tab lists every rule with its label and pattern. A rule is shown ticked when
its key is not in `hwpua_disabled_rules` and it either does not ship `#! default-off` or
its key is in `hwpua_enabled_rules`. Rules that ship off are marked "(off by default)".

In 1.0.0 the checkboxes are read-only, so rules are switched on and off with WP-CLI. Saving
the Settings form leaves `hwpua_disabled_rules` as it was, and never adds a default-off rule
to it. To switch a default-off rule on, add its key to `hwpua_enabled_rules`. To switch any
rule off, add its key to `hwpua_disabled_rules`, which takes precedence over both. Each
`wp option update` replaces the whole list, so include every key you want kept:

```bash
wp eval 'foreach ( Purge_User_Accounts\Pattern_Ruleset::parse_all_for_display() as $rule_entry ) { printf( "%s  %s  %s\n", $rule_entry["key"], $rule_entry["default_off"] ? "off" : "on ", $rule_entry["label"] ); }'

wp option update hwpua_disabled_rules '["0123456789abcdef"]' --format=json
wp option update hwpua_enabled_rules '["fedcba9876543210"]' --format=json
wp option update hwpua_custom_rules "$(cat site-rules.txt)"
```

A site rule with exactly the same pattern as a bundled rule has the same key and replaces
that rule's label and description.

The compiled ruleset also passes through the `hwpua_pattern_rules` filter; see
[hooks.md](hooks.md).

### What the bundled rules are

The bundled rules in `data/bad-signup-rules.txt` are **a record of campaigns already
seen**: throwaway mail providers, carrier SMS gateways, specific domain pools, generated
username shapes, and addresses that cannot exist. They are not a general theory of what a
bot account looks like. Several are literal lists that rotate out of date.

They were derived against a private corpus of roughly 203,000 accounts across 248 sites,
and that evidence does not automatically transfer to another site. **A site facing a
campaign the rules have not seen will need its own rules**, added through
`hwpua_custom_rules`, and a result from this criterion should always be reviewed before it
is acted on. Rules that matched real people during testing ship `#! default-off`.

---

## The email domain allowlist

Settings → **Email domain allowlist**. Addresses on these domains are never treated as
bad-signup pattern matches. It affects `wp-core.bad-signup-pattern` only, and only while
that criterion's `use_allowlist` is on. It does not spare anyone from any other criterion.
The allowlist ships empty.

### Format

```
# Health service
*.nhs.uk
nhs.net

# Universities
ac.uk
```

- One entry per line. Blank lines and lines starting with `#` are ignored.
- `*.nhs.uk` and `nhs.uk` mean the same: the domain **and everything beneath it**. A
  leading `*` and surrounding dots are removed.
- Entries are lowercased and converted to ASCII (punycode) when the `intl` extension is
  available.
- An entry must have at least two labels and be at most 253 characters. `com`, `uk` and
  `not_a_domain` are rejected with their line number when saving. The text is saved as
  typed; rejected lines are ignored.

### Matching

The domain is taken after the **last** `@` in the address, lowercased and converted the
same way. It is allowed when it equals an entry, or ends with `.` followed by the entry:

| Address | Entry | Result |
|---|---|---|
| `2623276r@student.gla.ac.uk` | `ac.uk` | allowed |
| `nurse@nhs.uk` | `nhs.uk` | allowed |
| `someone@notnhs.uk` | `nhs.uk` | not allowed — no dot boundary |
| `someone@nhs.uk.example.com` | `nhs.uk` | not allowed — suffix, not prefix |

### Checking an entry

On save, the Settings tab shows how many of the site's users each entry covers, and their
share of all users, using the same matcher. An entry such as `co.uk` or `gmail.com` shows
its reach immediately.

---

## Not in 1.0.0

- LearnDash criteria (course activity, enrolment) — planned for 1.1
- A registration-cluster criterion
- WooCommerce Subscriptions and Memberships criteria
