<?php
/**
 * Plugin-scope constants.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

// ============================================================================
// Capability & admin
// ============================================================================

/**
 * The capability that gates every screen, endpoint and CLI command.
 *
 * `delete_users` describes what this plugin does. `manage_options` would be an
 * unrelated proxy for "is an administrator".
 */
const REQUIRED_CAPABILITY = 'delete_users';

const ADMIN_PAGE_SLUG = 'hwpua-purge-user-accounts';

const TAB_BUILD    = 'build';
const TAB_RESULTS  = 'results';
const TAB_HISTORY  = 'history';
const TAB_SETTINGS = 'settings';
const TAB_HELP     = 'help';

/** Published documentation, linked from the Help tab. */
const DOCS_URL = 'https://github.com/headwalluk/purge-user-accounts/blob/main/docs/';

// ============================================================================
// Database tables - unprefixed; Schema::table() adds $wpdb->prefix
// ============================================================================

const TABLE_RUNS        = 'hwpua_runs';
const TABLE_RUN_ITEMS   = 'hwpua_run_items';
const TABLE_RUN_SCRATCH = 'hwpua_run_scratch';
const TABLE_JOBS        = 'hwpua_jobs';
const TABLE_JOB_ITEMS   = 'hwpua_job_items';

// ============================================================================
// WordPress options - prefix with OPT_
// ============================================================================

const OPT_SCHEMA_VERSION    = 'hwpua_schema_version';
const OPT_ACTIVATED_AT      = 'hwpua_activated_at';
const OPT_EXPORT_DIR_NAME   = 'hwpua_export_dir_name';
const OPT_EXPORT_DIR_ERROR  = 'hwpua_export_dir_error';
const OPT_DOMAIN_ALLOWLIST  = 'hwpua_email_domain_allowlist';
const OPT_DISABLED_RULES    = 'hwpua_disabled_rules';
const OPT_ENABLED_RULES     = 'hwpua_enabled_rules';
const OPT_CUSTOM_RULES      = 'hwpua_custom_rules';
const OPT_PRESETS           = 'hwpua_presets';
const OPT_LAST_LOGIN_SOURCE = 'hwpua_last_login_source';

// ============================================================================
// User meta keys - prefix with META_
// ============================================================================

const META_LAST_LOGIN           = 'hwpua_last_login';
const META_SIGNIN_BLOCKED       = 'hwpua_signin_blocked';
const META_SIGNIN_BLOCKED_AT    = 'hwpua_signin_blocked_at';
const META_STASHED_CAPABILITIES = 'hwpua_stashed_capabilities';
const META_STASHED_AT           = 'hwpua_stashed_at';
const META_STASHED_JOB_ID       = 'hwpua_stashed_job_id';

// ============================================================================
// Run & job lifecycle
// ============================================================================

const RUN_STATUS_BUILDING  = 'building';
const RUN_STATUS_COMPLETE  = 'complete';
const RUN_STATUS_FAILED    = 'failed';
const RUN_STATUS_ABANDONED = 'abandoned';

const JOB_STATUS_PENDING   = 'pending';
const JOB_STATUS_RUNNING   = 'running';
const JOB_STATUS_COMPLETE  = 'complete';
const JOB_STATUS_FAILED    = 'failed';
const JOB_STATUS_CANCELLED = 'cancelled';

const JOB_ITEM_FAILED  = 'failed';
const JOB_ITEM_SKIPPED = 'skipped';

// ============================================================================
// Stage kinds - see dev-notes/03-query-engine.md
// ============================================================================

const STAGE_PREPARE  = 'prepare';
const STAGE_SEED     = 'seed';
const STAGE_REFINE   = 'refine';
const STAGE_FINALISE = 'finalise';

// ============================================================================
// Filter sense
// ============================================================================

const SENSE_HAS     = 'has';
const SENSE_HAS_NOT = 'has_not';

// ============================================================================
// AJAX actions & nonces
// ============================================================================

const AJAX_START_RUN = 'hwpua_start_run';
const AJAX_STEP_RUN  = 'hwpua_step_run';
const AJAX_ESTIMATE  = 'hwpua_estimate';

const AJAX_PREFLIGHT = 'hwpua_preflight';
const AJAX_START_JOB = 'hwpua_start_job';
const AJAX_STEP_JOB  = 'hwpua_step_job';

const AJAX_SAVE_PRESET   = 'hwpua_save_preset';
const AJAX_DELETE_PRESET = 'hwpua_delete_preset';
const AJAX_IMPORT_PRESET = 'hwpua_import_preset';

const ADMIN_POST_EXPORT = 'hwpua_export';

const NONCE_ACTION = 'hwpua_admin';
const NONCE_FIELD  = 'hwpua_nonce';

// ============================================================================
// Cron
// ============================================================================

const CRON_HOUSEKEEPING = 'hwpua_housekeeping';

// ============================================================================
// Defaults - prefix with DEF_
// ============================================================================

/** Seed statement chunk size, in user IDs. Provisional - see Q1. */
const DEF_SEED_CHUNK = 25000;

/** Refinement stage chunk size. Fetch-bound, so generous. */
const DEF_REFINE_CHUNK = 10000;

/** Export streaming chunk size. */
const DEF_EXPORT_CHUNK = 2000;

/** Runs and run items are pruned after this many days. */
const DEF_RUN_RETENTION_DAYS = 30;

/** Jobs and job items - the audit trail - are kept far longer. */
const DEF_JOB_RETENTION_DAYS = 365;

/** Export files are purged from disk after this many seconds. */
const DEF_EXPORT_RETENTION_SECONDS = 6 * HOUR_IN_SECONDS;

/** A run older than this must be rebuilt before a destructive action. */
const DEF_RUN_STALE_SECONDS = DAY_IN_SECONDS;

/** Length of the generated export directory name. */
const DEF_EXPORT_DIR_NAME_LENGTH = 8;
