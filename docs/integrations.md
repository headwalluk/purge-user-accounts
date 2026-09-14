# Integrations

The developer contract for supplying criteria, actions and last-login sources.

An integration represents one system that owns user-attached data. It supplies any
combination of filters, actions and a last-login source. The engine knows nothing about the
data itself — only the SQL, scratch sets and chunk callbacks an integration hands it.

## What ships in 1.0.0

| Integration ID | Label | Available when | Supplies |
|---|---|---|---|
| `wp-core` | WordPress core | Always | Nine filters, eight actions, native last-login source |
| `woocommerce` | WooCommerce | `class_exists( 'WooCommerce' )` | `woocommerce.orders`, the `woocommerce-last-active` source |

See [filters.md](filters.md) and [actions.md](actions.md) for what each supplies.

## Registering

`hwpua_register_integrations` is the single extension point. There are no separate hooks
for registering a filter, action or source on its own.

```php
add_filter(
	'hwpua_register_integrations',
	static function ( array $integrations ): array {
		$integrations[] = new Example_Forum_Integration();

		return $integrations;
	}
);
```

- Anything in the returned array that is not an `Integration` is ignored and reported
  through `hwpua_error`.
- Integrations are keyed by `get_id()`. A later integration with the same ID replaces an
  earlier one.
- The registry, filter list, action list and source list are built once per request, the
  first time any of them is needed, and cached. Register the filter while plugins load.

Your classes extend classes from this plugin, so load them only once it is active. Plugins
load in alphabetical order, so defer to `plugins_loaded`:

```php
add_action( 'plugins_loaded', 'example_forum_register_purge_integration' );

/**
 * Register the forum integration when Purge User Accounts is active.
 */
function example_forum_register_purge_integration(): void {
	if ( class_exists( '\Purge_User_Accounts\Integration' ) ) {
		require_once __DIR__ . '/class-example-forum-integration.php';

		add_filter(
			'hwpua_register_integrations',
			static function ( array $integrations ): array {
				$integrations[] = new Example_Forum_Integration();

				return $integrations;
			}
		);
	}
}
```

## `Integration`

`Purge_User_Accounts\Integration` — `includes/class-integration.php`.

| Method | Default | Purpose |
|---|---|---|
| `get_id(): string` | abstract | Short ID, used as the namespace for your filter IDs |
| `get_label(): string` | abstract | Name shown in the UI |
| `is_available(): bool` | `true` | Whether the system is present on this site |
| `get_unavailable_reason(): string` | `''` | Shown on the Build tab when unavailable |
| `get_filters(): array` | `array()` | `Filter` instances |
| `get_actions(): array` | `array()` | `Action` instances |
| `get_last_login_source(): ?Last_Login_Source` | `null` | A source, if any |

When `is_available()` is false, none of the integration's filters, actions or source are
registered, and the Build tab shows the label and reason in place of its criteria.

## Filters

`Purge_User_Accounts\Filter` — `includes/class-filter.php`.

| Method | Default | Purpose |
|---|---|---|
| `get_id(): string` | abstract | Namespaced ID, `<integration-id>.<name>` |
| `get_label(): string` | abstract | Criterion label |
| `get_group(): string` | `'general'` | Build tab group |
| `get_kind(): string` | `Filter::KIND_SQL` | `KIND_SQL` or `KIND_REFINE` |
| `is_available(): bool` | `true` | Criterion-level availability |
| `get_unavailable_reason(): string` | `''` | Shown beside the disabled criterion |
| `supports_sense(): bool` | `true` | Whether has / has none apply; when false the criterion is always applied as `has` |
| `get_argument_error( array $args ): string` | `''` | Why the arguments cannot express a usable criterion |
| `get_scratch_bucket(): string` | `''` | Scratch bucket name, or empty for none |
| `get_scratch_queries( int $run_id, array $args ): array` | `array()` | Statements that fill the bucket |
| `get_predicate( array $args, int $run_id ): array` | `array( '1=1', array() )` | `WHERE` fragment and parameters |
| `get_chunk_size(): int` | `10000` | Rows per refine chunk |
| `get_required_columns(): array` | `array()` | `wp_users` columns a refine filter needs |
| `evaluate_chunk( array $rows, array $args ): array` | `array()` | IDs to drop from a refine chunk |
| `get_last_match_labels(): array` | `array()` | User ID ⇒ reason for the last chunk |

A filter contributes in one of three ways, in order of preference.

### 1. A predicate

The default, and the cheapest. The fragment is folded into the seed statement:

```sql
INSERT IGNORE INTO {prefix}hwpua_run_items (run_id, user_id, matched_rule)
SELECT %d, u.ID, NULL FROM {prefix}users u
WHERE u.ID > %d AND u.ID <= %d AND ( your predicate ) AND NOT ( another predicate ) ...
```

Rules for the fragment:

- Reference the users table as **`u`**.
- It must be correct when wrapped in `NOT ( … )`. Express the **positive** condition; the
  engine adds `NOT` for `has_not`.
- Return `array( $sql_fragment, $parameters )`. The whole statement goes through
  `$wpdb->prepare()`, so use `%s` / `%d` placeholders, bind every value, and never put a
  literal `%` in the fragment — pass `LIKE` patterns as parameters.
- Use a correlated `EXISTS` only against a table with an index on its user column.
- Do not return a match-nothing predicate such as `'1=0'` for unusable arguments: `has_not`
  would invert it into "matches everybody". Implement `get_argument_error()` instead.

### Validating arguments

`get_argument_error( array $args ): string` returns `''` when the arguments are usable, or a
short message saying what is missing. `Run_Builder::validate()` calls it for every criterion
and, when it returns a message, refuses the whole specification with
*The criterion "&lt;label&gt;" is incomplete: &lt;message&gt;*. Nothing runs. That happens
when a query is estimated or run, and when a preset is saved or imported.

It exists so a filter never has to return a match-nothing predicate for arguments it cannot
use, which `has_not` would turn into every user. Validate in `get_argument_error()`, and let
`get_predicate()` assume usable arguments.

```php
/**
 * Refuse the criterion without a cutoff.
 *
 * @param array<string,mixed> $args Operator-supplied arguments.
 */
public function get_argument_error( array $args ): string {
	return '' === hwpua_resolve_cutoff( $args ) ? __( 'give a number of days or a date.', 'example-forum' ) : '';
}
```

`hwpua_resolve_cutoff()` turns a `days_ago` or `date` argument into a UTC `Y-m-d H:i:s`
string, or `''` when neither is usable.

### 2. A scratch bucket

For a source table with no usable index on its user column. A correlated subquery against
such a table is re-evaluated for every seed chunk; materialising the set once is faster.

- `get_scratch_bucket()` returns a name of at most 32 characters, unique to your filter.
- `get_scratch_queries()` returns a list of `array( $sql, $parameters )`, each an
  `INSERT IGNORE INTO` the scratch table `(run_id, bucket, user_id)`.
- `get_predicate()` then tests membership of that bucket for `$run_id`.
- One prepare stage runs per distinct bucket, using the arguments of the **first**
  criterion in the specification with that bucket. If the same filter can appear twice
  with different arguments, the second use shares the first's set.
- Scratch rows are deleted when the run completes or fails.

### 3. A refine callback

When the condition cannot be expressed in SQL. Return `Filter::KIND_REFINE` from
`get_kind()`.

- The engine reads chunks of stored results joined to `wp_users`. Each row has `ID` plus
  any of `user_login`, `user_nicename`, `user_email`, `user_url`, `user_registered` and
  `display_name` named in `get_required_columns()`. Any other column name is ignored and
  reported.
- `evaluate_chunk()` returns the IDs that **fail the positive condition** — a drop list for
  `has`. The engine inverts it for `has_not`. Never reason about sense in the callback, and
  never return a keep list.
- A refine stage only ever removes rows, so it runs after the seed and sees only users that
  survived the SQL criteria.
- To record a reason, fill a property during `evaluate_chunk()` and return it from
  `get_last_match_labels()` as user ID ⇒ label. Labels are written, truncated to 255
  characters, for users still in the run under `has`.

### There is no per-user callback

The engine never asks a filter about one user at a time. Hydrating user rows costs about
52 MB per 100,000 users and issues a query per user per filter, which defeats the
`INSERT … SELECT` seed the memory design depends on.

`Integration_Registry` enforces this. A filter that declares a method named
`evaluate_user`, `evaluate_single` or `score_user` is **refused** — not registered — and the
refusal is reported through `hwpua_error`.

### Fail closed

A source that cannot be consulted must stop the run, never look like a source with no rows.

- A scratch statement, seed insert or refine delete that returns a database error stops the
  run automatically.
- Inside `evaluate_chunk()` or `get_scratch_queries()`, throw
  `Purge_User_Accounts\Run_Exception` with an operator-safe, escaped message. The run is
  marked `failed` with that message. Other exception types are not caught by the stepper;
  catch them and rethrow as `Run_Exception`.

### Identifiers

Name filters `<integration-id>.<positive-condition>` — `woocommerce.orders`, not
`woocommerce.never-purchased`. The part before the first `.` is how a preset lists the
integrations it needs, and how import reports "Integration "example-forum" is not installed
here." The registry does not enforce the prefix, and a later filter with the same ID
replaces an earlier one.

### How the Build tab renders a filter

- Filters are rendered by `get_group()`, in this order: `roles`, `content`, `purchases`,
  `login`, `patterns`, `registration`, `general`. **A filter with any other group key is not
  shown.**
- The has / has none control appears when `supports_sense()` is true. Otherwise there is no
  control and the criterion is applied as `has`.
- Argument controls exist only for the built-in criteria. A third-party filter receives
  `args` of `{}` from the Build tab; arguments can be supplied through a preset.

## Actions

`Purge_User_Accounts\Action` — `includes/class-action.php`.

| Method | Default | Purpose |
|---|---|---|
| `get_id(): string` | abstract | Stable ID, stored on the job row (max 64 characters) |
| `get_label(): string` | abstract | Name on the action list |
| `get_description(): string` | abstract | One sentence for the confirmation |
| `apply( array $user_ids, array $args ): Action_Result` | abstract | Act on one chunk |
| `get_destructiveness(): int` | `50` | 0–100; ordering and friction |
| `is_reversible(): bool` | `false` | Label on the action list |
| `requires_export(): bool` | `true` | Block until an export of the run exists |
| `allows_privileged_opt_in(): bool` | `true` | Whether `--allow-privileged` may apply |
| `get_confirmation_phrase( Run $run ): string` | match count | What must be typed when destructiveness ≥ 50 |
| `is_available(): bool` | `true` | Disabled on the list when false |
| `get_unavailable_reason(): string` | `''` | Shown in place of the description |
| `get_chunk_size(): int` | `1000` | Users per step (minimum 10) |
| `get_rate_per_second(): float` | `500.0` | Starting estimate |
| `get_fields(): array` | `array()` | Not used by the admin screen or WP-CLI in 1.0.0 |

`apply()` receives IDs that have already passed the guards. Record each outcome on an
`Action_Result`:

| Method | Records |
|---|---|
| `succeed(): void` | One success (counted, not stored per user) |
| `fail( int $user_id, string $message ): void` | A failure, written to `hwpua_job_items` |
| `skip( int $user_id, string $message ): void` | A deliberate skip, written to `hwpua_job_items` |

Catch per-user exceptions and record them with `fail()`; do not let one user abort the job.

Change users only through WordPress APIs. Never use `wp_update_user()` to change a password —
it emails the user.

The `args` an action receives come from the job: `dry_run`, `allow_privileged` and, for the
`delete` action, `reassign_to`. The admin screen and WP-CLI offer no way to pass other
arguments in 1.0.0. The list orders all actions by destructiveness; action IDs are not
namespaced by integration in 1.0.0, so choose an ID that will not collide.

## Last-login sources

`Purge_User_Accounts\Last_Login_Source` — `includes/class-last-login-source.php`. A concrete
class describing a user meta key:

```php
new Last_Login_Source(
	string $id,
	string $label,
	int $priority,           // lower wins
	string $meta_key,
	string $value_format,    // Last_Login_Source::FORMAT_UNIX or FORMAT_DATETIME (UTC)
	string $semantics,       // Last_Login_Source::SEMANTICS_LOGIN or SEMANTICS_ACTIVITY
	string $earliest_hint = '' // known start date, 'Y-m-d H:i:s' UTC
);
```

- Only one source is active: the lowest priority number among available integrations,
  unless option `hwpua_last_login_source` names another. The native source has priority
  10, so a new source is used only when selected through that option or given a lower
  number.
- `SEMANTICS_ACTIVITY` makes the Build tab warn that a record means *seen*, not *logged in*.
- `earliest_hint` is used as the earliest record when it is older than the oldest stored
  value. Give one if your system knows when it began recording — it sizes the Unknown
  cohort.

## Example

A forum plugin storing posts in `{prefix}example_forum_posts`, with an index on `user_id`,
and a last-visit timestamp in user meta.

```php
<?php
/**
 * Purge User Accounts integration for Example Forum.
 *
 * @package ExampleForum
 */

use Purge_User_Accounts\Action;
use Purge_User_Accounts\Action_Result;
use Purge_User_Accounts\Filter;
use Purge_User_Accounts\Integration;
use Purge_User_Accounts\Last_Login_Source;

defined( 'ABSPATH' ) || die();

/**
 * Has published forum posts.
 */
class Example_Forum_Posts_Filter extends Filter {

	/**
	 * Namespaced identifier.
	 */
	public function get_id(): string {
		return 'example-forum.posts';
	}

	/**
	 * Criterion label.
	 */
	public function get_label(): string {
		return __( 'Has forum posts', 'example-forum' );
	}

	/**
	 * Shown with the other content criteria.
	 */
	public function get_group(): string {
		return 'content';
	}

	/**
	 * Matches users with at least one published forum post.
	 *
	 * @param array<string,mixed> $args   Unused.
	 * @param int                 $run_id Unused.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function get_predicate( array $args, int $run_id ): array {
		global $wpdb;

		unset( $args, $run_id );

		$forum_table = $wpdb->prefix . 'example_forum_posts';

		return array(
			"EXISTS ( SELECT 1 FROM {$forum_table} fp WHERE fp.user_id = u.ID AND fp.status = %s )",
			array( 'published' ),
		);
	}
}

/**
 * Removes forum subscriptions.
 */
class Example_Forum_Unsubscribe_Action extends Action {

	/**
	 * Stable identifier.
	 */
	public function get_id(): string {
		return 'example-forum-unsubscribe';
	}

	/**
	 * Action label.
	 */
	public function get_label(): string {
		return __( 'Remove forum subscriptions', 'example-forum' );
	}

	/**
	 * One sentence for the confirmation.
	 */
	public function get_description(): string {
		return __( 'Removes every forum subscription. Accounts are otherwise untouched.', 'example-forum' );
	}

	/**
	 * Low friction.
	 */
	public function get_destructiveness(): int {
		return 20;
	}

	/**
	 * Nothing to reconstruct.
	 */
	public function requires_export(): bool {
		return false;
	}

	/**
	 * Unsubscribe each user in the chunk.
	 *
	 * @param int[]               $user_ids Users that passed the guards.
	 * @param array<string,mixed> $args     Job arguments.
	 * @return Action_Result
	 */
	public function apply( array $user_ids, array $args ): Action_Result {
		unset( $args );

		$result = new Action_Result();

		foreach ( $user_ids as $user_id ) {
			try {
				example_forum_remove_subscriptions( $user_id );
				$result->succeed();
			} catch ( \Throwable $caught_error ) {
				$result->fail( $user_id, $caught_error->getMessage() );
			}
		}

		return $result;
	}
}

/**
 * Example Forum.
 */
class Example_Forum_Integration extends Integration {

	/**
	 * Namespace for filter IDs.
	 */
	public function get_id(): string {
		return 'example-forum';
	}

	/**
	 * Integration label.
	 */
	public function get_label(): string {
		return __( 'Example Forum', 'example-forum' );
	}

	/**
	 * Present only when the forum plugin is active.
	 */
	public function is_available(): bool {
		return defined( 'EXAMPLE_FORUM_VERSION' );
	}

	/**
	 * Shown on the Build tab when unavailable.
	 */
	public function get_unavailable_reason(): string {
		return __( 'Example Forum is not active on this site.', 'example-forum' );
	}

	/**
	 * Criteria supplied.
	 *
	 * @return Filter[]
	 */
	public function get_filters(): array {
		return array( new Example_Forum_Posts_Filter() );
	}

	/**
	 * Actions supplied.
	 *
	 * @return Action[]
	 */
	public function get_actions(): array {
		return array( new Example_Forum_Unsubscribe_Action() );
	}

	/**
	 * The forum's last-visit timestamp, an activity signal.
	 *
	 * @return Last_Login_Source|null
	 */
	public function get_last_login_source(): ?Last_Login_Source {
		return new Last_Login_Source(
			'example-forum-last-visit',
			__( 'Example Forum last visit', 'example-forum' ),
			30,
			'example_forum_last_visit',
			Last_Login_Source::FORMAT_UNIX,
			Last_Login_Source::SEMANTICS_ACTIVITY
		);
	}
}
```

Registered with the `plugins_loaded` snippet under [Registering](#registering).

### A scratch-backed variant

If `example_forum_posts` had no index on `user_id`:

```php
/**
 * Scratch bucket for forum posters.
 */
public function get_scratch_bucket(): string {
	return 'example_forum_poster';
}

/**
 * Materialise every user with a published post, once per run.
 *
 * @param int                 $run_id Run being built.
 * @param array<string,mixed> $args   Unused.
 * @return array<int,array{0:string,1:array<int,mixed>}>
 */
public function get_scratch_queries( int $run_id, array $args ): array {
	global $wpdb;

	unset( $args );

	$scratch_table = \Purge_User_Accounts\Schema::table( \Purge_User_Accounts\TABLE_RUN_SCRATCH );
	$forum_table   = $wpdb->prefix . 'example_forum_posts';

	return array(
		array(
			"INSERT IGNORE INTO {$scratch_table} (run_id, bucket, user_id)
			 SELECT %d, 'example_forum_poster', fp.user_id FROM {$forum_table} fp
			 WHERE fp.user_id > 0 AND fp.status = %s GROUP BY fp.user_id",
			array( $run_id, 'published' ),
		),
	);
}

/**
 * Matches users in the materialised set.
 *
 * @param array<string,mixed> $args   Unused.
 * @param int                 $run_id Run being built.
 * @return array{0:string,1:array<int,mixed>}
 */
public function get_predicate( array $args, int $run_id ): array {
	unset( $args );

	$scratch_table = \Purge_User_Accounts\Schema::table( \Purge_User_Accounts\TABLE_RUN_SCRATCH );

	return array(
		"EXISTS ( SELECT 1 FROM {$scratch_table} s WHERE s.run_id = %d AND s.bucket = 'example_forum_poster' AND s.user_id = u.ID )",
		array( $run_id ),
	);
}
```

### A refine variant

```php
/**
 * Evaluated in PHP.
 */
public function get_kind(): string {
	return self::KIND_REFINE;
}

/**
 * Columns loaded for each row.
 *
 * @return string[]
 */
public function get_required_columns(): array {
	return array( 'user_email' );
}

/**
 * Drop users whose address is not on a reserved test domain.
 *
 * @param array<int,object>   $rows Chunk rows.
 * @param array<string,mixed> $args Unused.
 * @return int[]
 */
public function evaluate_chunk( array $rows, array $args ): array {
	unset( $args );

	$drop_ids = array();

	foreach ( $rows as $row ) {
		if ( ! str_ends_with( strtolower( (string) $row->user_email ), '.invalid' ) ) {
			$drop_ids[] = (int) $row->ID;
		}
	}

	return $drop_ids;
}
```

## Export columns

There is no per-integration export column method in 1.0.0. Add user meta columns to the
CSV with the `hwpua_csv_columns` filter; see [hooks.md](hooks.md).

## Not in 1.0.0

- A LearnDash integration (planned for 1.1)
- Per-integration settings or export columns
- Operator-supplied arguments for third-party filters and actions on the admin screen
