# Integrations

> **Status: pre-release.** The contract is settled in design; this page is completed as the
> framework is built.

Selection criteria are grouped by **the system that owns the data**. Each integration is
one class in `integrations/`, self-registering on `hwpua_register_integrations`.

## What ships

| Integration | Supplies | Version |
|---|---|---|
| **WP Core** | Roles, content, comments, bad-signup patterns, registration date, native last-login recorder | 1.0.0 |
| **WooCommerce** | Purchase history, `wc_last_active` as a last-activity source | 1.0.0 |
| **LearnDash** | Course activity, enrolment, `learndash-last-login` as a login source | 1.1 |

An integration whose plugin is not active is **disabled with the reason shown**, never
hidden.

## What an integration supplies

```php
abstract class Integration {
    abstract public function get_id(): string;
    abstract public function get_label(): string;

    public function is_available(): bool;
    public function get_unavailable_reason(): string;

    public function get_filters(): array;
    public function get_actions(): array;
    public function get_last_login_source(): ?Last_Login_Source;
    public function get_export_columns(): array;
    public function get_defaults(): array;

    public function render_settings( Settings $settings ): void;
    public function save_settings( Settings $settings ): void;
}
```

Any subset is valid. An integration that only supplies a last-login source is perfectly
reasonable.

## How a filter contributes

Exactly one of three ways, in order of preference:

```php
// 1. A predicate folded into the main selection statement.
public function get_predicate( array $args ): array;      // [ $sql_fragment, $params ]

// 2. When the target table has no index on its user column — materialise a set first.
public function get_scratch_query( int $run_id ): array;

// 3. When it cannot be expressed in SQL. Receives a CHUNK of rows.
public function evaluate_chunk( array $rows, array $args ): array;   // user IDs to DROP
```

### There is no per-user callback

The engine never iterates users asking each integration about one at a time. That would
hydrate rows at roughly 52 MB per 100,000 users and issue a query per user per integration.

`evaluate_chunk()` is the escape hatch and it is deliberately chunk-shaped. If your
integration genuinely needs per-user work, open an issue rather than working around this —
the constraint is load-bearing.

## Identifiers

Filter and action IDs are namespaced by integration:

```
wp-core.no-content
woocommerce.never-purchased
learndash.no-course-activity
```

This is what lets a saved query report `requires: wp-core, woocommerce` when imported onto
another site.

## Adding your own

```php
add_filter( 'hwpua_register_integrations', function ( array $integration_list ): array {
    $integration_list[] = new My_Integration();
    return $integration_list;
} );
```

`hwpua_register_integrations` is the single extension point. See [hooks.md](hooks.md) for
the lifecycle hooks available to an integration once registered.
