# Filters

> **Status: pre-release.** Semantics are settled in design; this page is completed as each
> filter is built.

Selection criteria. All criteria combine with **AND**; each is independently optional.

Criteria are supplied by [integrations](integrations.md), and their IDs are namespaced
accordingly.

| ID | Criterion | Integration |
|---|---|---|
| `wp-core.role` | Holds one of the named roles | WP Core |
| `wp-core.any-role` | Holds any role at all | WP Core |
| `wp-core.content` | Has authored content | WP Core |
| `wp-core.comments` | Has left comments | WP Core |
| `wp-core.registered` | Registration date, before or after | WP Core |
| `wp-core.login-record` | Has a login record | WP Core *(needs a source)* |
| `wp-core.active-since` | Has logged in since a date | WP Core *(needs a source)* |
| `wp-core.bad-signup-pattern` | Email or username matches a bad-signup pattern | WP Core |
| `woocommerce.orders` | Has WooCommerce orders | WooCommerce |
| `learndash.course-activity` | Has course activity | LearnDash *(1.1)* |
| `learndash.enrolment` | Enrolled on a course | LearnDash *(1.1)* |

Each criterion names the **positive** condition and carries a sense — `has` or `has not`.
So "has never purchased" is `woocommerce.orders` with sense *has not*, and "is a customer"
is the same criterion with sense *has*. One criterion, both directions.

Unavailable criteria are shown **disabled with the reason stated**, never hidden.

## Notes that matter

**Spam comments do not count as comments.** An account whose only comments were marked
spam or trashed has contributed nothing, and stays eligible.

**"No role at all" is a separate option.** Users with no capabilities row are
invisible to both `role in` and `role not in`. They are common among bulk bot signups,
and every role-based tool misses them.

**F2 — attachments are not counted as content by default.** An avatar upload is not
authorship. `auto-draft` and `revision` are always excluded; visiting the new-post screen
once should not spare an account.

**F5 — guest orders are checked by email by default.** A customer who ordered as a guest
before registering has `customer_id = 0` on that order. Matching on customer ID alone
marks them as never-purchased and deletes them along with the link to their history.
Turning this check off is a deliberate act.

**F6 is not "never logged in".** It is "no record of a login". Those differ whenever the
data source started recording after the account was created — which is the normal case.
See [operators-guide.md](operators-guide.md#last-login-data).

**The email domain allowlist.** Under Settings you can list domains — one per line,
`*.nhs.uk` style wildcards accepted — whose addresses are never treated as bad-signup
pattern matches. An entry covers the domain and everything beneath it, so `ac.uk` also
covers `student.gla.ac.uk`. When you save, the screen shows how many of your users each
entry matches, which makes an over-broad entry obvious immediately.

The allowlist only affects the bad-signup pattern criterion. It does not spare users from
any other filter — if you want to keep customers, say so in the query.

**F9 is mostly a safety filter.** "Registered more than 90 days ago" protects a legitimate
new signup who has not yet had time to log in, comment or buy anything. Recommended
on for most queries.

## Extending

Filters are supplied by integrations. See [integrations.md](integrations.md) for the
contract and the `hwpua_register_integrations` hook.
