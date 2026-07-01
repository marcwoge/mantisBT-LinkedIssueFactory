<?php
/**
 * LinkedIssueFactory – persist a recurring schedule.
 *
 * Validates the submitted schedule form and inserts / updates the row in the
 * plugin's own schedule table. Field snapshots (standard + custom) are captured
 * from the current source ticket so the cron runner can recreate the ticket
 * even if the source is later changed or removed.
 *
 * @package LinkedIssueFactory
 * @license MIT
 */

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );

auth_ensure_user_authenticated();

if( !linked_issue_factory_config( 'enable_recurring', true ) ) {
	access_denied();
}

form_security_validate( 'plugin_LinkedIssueFactory_schedule_save' );

$f_schedule_id        = gpc_get_int( 'schedule_id', 0 );
$f_source_bug_id      = gpc_get_int( 'source_bug_id' );
$f_target_project_id  = gpc_get_int( 'target_project_id' );
$f_relation_type      = gpc_get_int( 'relation_type', linked_issue_factory_default_relationship_type() );
$f_summary            = gpc_get_string( 'summary_template', '' );
$f_description        = gpc_get_string( 'description_template', '' );
$f_steps              = gpc_get_string( 'steps_to_reproduce_template', '' );
$f_additional         = gpc_get_string( 'additional_information_template', '' );
$f_copy_standard      = gpc_get_bool( 'copy_standard_fields', false ) ? 1 : 0;
$f_copy_custom        = gpc_get_bool( 'copy_custom_fields', false ) ? 1 : 0;
$f_cf_strategy        = gpc_get_string( 'custom_field_strategy', 'linked_or_name' );
$f_recurrence_type    = gpc_get_string( 'recurrence_type', 'monthly' );
$f_recurrence_interval= gpc_get_int( 'recurrence_interval', 1 );
$f_recurrence_unit    = gpc_get_string( 'recurrence_unit', 'days' );
$f_start_at_str       = gpc_get_string( 'start_at', '' );
$f_end_at_str         = gpc_get_string( 'end_at', '' );
$f_max_occurrences    = gpc_get_int( 'max_occurrences', 0 );
$f_active             = gpc_get_bool( 'active', false ) ? 1 : 0;

# ---------------------------------------------------------------------------
# Validation / access.
# ---------------------------------------------------------------------------
bug_ensure_exists( $f_source_bug_id );
access_ensure_bug_level( config_get( 'view_bug_threshold' ), $f_source_bug_id );

if( !project_exists( $f_target_project_id ) ) {
	error_parameters( $f_target_project_id );
	trigger_error( ERROR_PROJECT_NOT_FOUND, ERROR );
}
access_ensure_project_level( config_get( 'report_bug_threshold' ), $f_target_project_id );

# Sanitize enum-like values against the allowed sets.
if( !linked_issue_factory_relationship_is_valid( $f_relation_type ) ) {
	$f_relation_type = linked_issue_factory_default_relationship_type();
}
if( !in_array( $f_recurrence_type, linked_issue_factory_recurrence_types(), true ) ) {
	$f_recurrence_type = 'monthly';
}
if( !in_array( $f_recurrence_unit, linked_issue_factory_recurrence_units(), true ) ) {
	$f_recurrence_unit = 'days';
}
if( !in_array( $f_cf_strategy, array( 'linked_or_name', 'linked_only' ), true ) ) {
	$f_cf_strategy = 'linked_or_name';
}
if( $f_recurrence_interval < 1 ) {
	$f_recurrence_interval = 1;
}
if( $f_max_occurrences < 0 ) {
	$f_max_occurrences = 0;
}
if( is_blank( $f_summary ) ) {
	error_parameters( lang_get( 'summary' ) );
	trigger_error( ERROR_EMPTY_FIELD, ERROR );
}
if( is_blank( $f_description ) ) {
	error_parameters( lang_get( 'description' ) );
	trigger_error( ERROR_EMPTY_FIELD, ERROR );
}

# Parse dates (normal_date_format text -> Unix timestamp).
$t_start_at = 0;
if( !is_blank( $f_start_at_str ) ) {
	$t_parsed = strtotime( $f_start_at_str );
	$t_start_at = ( $t_parsed !== false ) ? $t_parsed : strtotime( 'today' );
} else {
	$t_start_at = strtotime( 'today' );
}
$t_end_at = 0;
if( !is_blank( $f_end_at_str ) ) {
	$t_parsed = strtotime( $f_end_at_str );
	$t_end_at = ( $t_parsed !== false ) ? $t_parsed : 0;
}

# Snapshots captured from the current source ticket.
$t_std_snapshot = json_encode( linked_issue_factory_snapshot_standard_fields( $f_source_bug_id ) );
$t_cf_snapshot  = json_encode( linked_issue_factory_snapshot_custom_fields( $f_source_bug_id ) );

$t_now = db_now();
$t_table = linked_issue_factory_schedule_table();

if( $f_schedule_id > 0 ) {
	# ---- Update -----------------------------------------------------------
	# Preserve occurrences_created / last_created_bug_id; only reset next_run_at
	# when nothing has been created yet (so an active cadence is not disrupted).
	db_param_push();
	$t_existing = db_fetch_array( db_query(
		'SELECT occurrences_created, next_run_at FROM ' . $t_table . ' WHERE id = ' . db_param(),
		array( $f_schedule_id ) ) );
	if( !$t_existing ) {
		trigger_error( ERROR_GENERIC, ERROR );
	}
	$t_next_run_at = ( (int)$t_existing['occurrences_created'] === 0 )
		? $t_start_at
		: (int)$t_existing['next_run_at'];

	db_param_push();
	$t_query = 'UPDATE ' . $t_table . ' SET '
		. 'target_project_id = ' . db_param() . ', '
		. 'relation_type = ' . db_param() . ', '
		. 'summary_template = ' . db_param() . ', '
		. 'description_template = ' . db_param() . ', '
		. 'steps_to_reproduce_template = ' . db_param() . ', '
		. 'additional_information_template = ' . db_param() . ', '
		. 'standard_fields_snapshot = ' . db_param() . ', '
		. 'custom_fields_snapshot = ' . db_param() . ', '
		. 'copy_standard_fields = ' . db_param() . ', '
		. 'copy_custom_fields = ' . db_param() . ', '
		. 'custom_field_strategy = ' . db_param() . ', '
		. 'recurrence_type = ' . db_param() . ', '
		. 'recurrence_interval = ' . db_param() . ', '
		. 'recurrence_unit = ' . db_param() . ', '
		. 'start_at = ' . db_param() . ', '
		. 'next_run_at = ' . db_param() . ', '
		. 'end_at = ' . db_param() . ', '
		. 'max_occurrences = ' . db_param() . ', '
		. 'active = ' . db_param() . ', '
		. 'updated_at = ' . db_param() . ' '
		. 'WHERE id = ' . db_param();
	db_query( $t_query, array(
		$f_target_project_id, $f_relation_type, $f_summary, $f_description,
		$f_steps, $f_additional, $t_std_snapshot, $t_cf_snapshot,
		$f_copy_standard, $f_copy_custom, $f_cf_strategy,
		$f_recurrence_type, $f_recurrence_interval, $f_recurrence_unit,
		$t_start_at, $t_next_run_at, $t_end_at, $f_max_occurrences,
		$f_active, $t_now, $f_schedule_id,
	) );
} else {
	# ---- Insert -----------------------------------------------------------
	db_param_push();
	$t_query = 'INSERT INTO ' . $t_table . ' ('
		. 'source_bug_id, target_project_id, relation_type, summary_template, '
		. 'description_template, steps_to_reproduce_template, additional_information_template, '
		. 'standard_fields_snapshot, custom_fields_snapshot, copy_standard_fields, '
		. 'copy_custom_fields, custom_field_strategy, recurrence_type, recurrence_interval, '
		. 'recurrence_unit, start_at, next_run_at, end_at, max_occurrences, '
		. 'occurrences_created, active, last_created_bug_id, created_by, created_at, updated_at'
		. ') VALUES ('
		. db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param()
		. ')';
	db_query( $t_query, array(
		$f_source_bug_id, $f_target_project_id, $f_relation_type, $f_summary,
		$f_description, $f_steps, $f_additional,
		$t_std_snapshot, $t_cf_snapshot, $f_copy_standard,
		$f_copy_custom, $f_cf_strategy, $f_recurrence_type, $f_recurrence_interval,
		$f_recurrence_unit, $t_start_at, $t_start_at, $t_end_at, $f_max_occurrences,
		0, $f_active, 0, auth_get_current_user_id(), $t_now, $t_now,
	) );
}

form_security_purge( 'plugin_LinkedIssueFactory_schedule_save' );
print_successful_redirect( plugin_page( 'schedule_list', true ) );
