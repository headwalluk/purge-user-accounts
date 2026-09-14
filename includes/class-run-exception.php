<?php
/**
 * Operator-safe engine failure.
 *
 * @package PurgeUserAccounts
 */

namespace Purge_User_Accounts;

defined( 'ABSPATH' ) || die();

/**
 * An exception whose message is safe to show an administrator.
 *
 * Thrown whenever a stage cannot complete honestly. The engine fails closed: a
 * data source that could not be consulted must abort the run rather than let
 * every user look like they have no orders, no comments or no content. See
 * dev-notes/09-safety-model.md §6.
 */
class Run_Exception extends \RuntimeException {}
