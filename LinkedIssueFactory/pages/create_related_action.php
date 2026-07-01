<?php
/**
 * LinkedIssueFactory – create-a-linked-ticket action (mode: once).
 *
 * Validates the submitted form, creates the new ticket in the target project
 * (delegating access control, custom-field validation and relationship
 * creation to MantisBT's IssueAddCommand) and adds the cross-reference notes.
 *
 * @package LinkedIssueFactory
 * @license MIT
 */

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'custom_field_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );

use Mantis\Exceptions\ClientException;

auth_ensure_user_authenticated();
form_security_validate( 'plugin_LinkedIssueFactory_create' );

$f_bug_id             = gpc_get_int( 'bug_id' );
$f_target_project_id  = gpc_get_int( 'target_project_id' );
$f_summary            = gpc_get_string( 'summary', '' );
$f_description        = gpc_get_string( 'description', '' );
$f_steps             = gpc_get_string( 'steps_to_reproduce', '' );
$f_additional        = gpc_get_string( 'additional_information', '' );
$f_category_id        = gpc_get_int( 'category_id', 0 );
$f_priority           = gpc_get_int( 'priority', 0 );
$f_severity           = gpc_get_int( 'severity', 0 );
$f_reproducibility    = gpc_get_int( 'reproducibility', 0 );
$f_handler_id         = gpc_get_int( 'handler_id', 0 );
$f_view_state         = gpc_get_int( 'view_state', 0 );
$f_due_date_str       = gpc_get_string( 'due_date', '' );
$f_relationship_type  = gpc_get_int( 'relationship_type', linked_issue_factory_default_relationship_type() );

# ---------------------------------------------------------------------------
# Baseline access checks. IssueAddCommand re-checks everything, but failing
# fast here yields clearer error messages.
# ---------------------------------------------------------------------------
bug_ensure_exists( $f_bug_id );
access_ensure_bug_level( config_get( 'view_bug_threshold' ), $f_bug_id );

if( !project_exists( $f_target_project_id ) ) {
	error_parameters( $f_target_project_id );
	trigger_error( ERROR_PROJECT_NOT_FOUND, ERROR );
}
access_ensure_project_level( config_get( 'report_bug_threshold' ), $f_target_project_id );

if( !linked_issue_factory_relationship_is_valid( $f_relationship_type ) ) {
	$f_relationship_type = linked_issue_factory_default_relationship_type();
}

# Make category / custom-field lookups resolve against the target project.
global $g_project_override;
$g_project_override = $f_target_project_id;

# ---------------------------------------------------------------------------
# Collect custom fields. Posted values (including required ones the user filled
# and same-id fields pre-filled from the source) take precedence; the by-name /
# compatible-type fallback fills any target field the user left untouched.
# ---------------------------------------------------------------------------
$t_custom_fields = array();
$t_posted_ids = array();

$t_target_cf_ids = custom_field_get_linked_ids( $f_target_project_id );
foreach( $t_target_cf_ids as $t_cf_id ) {
	$t_def = custom_field_get_definition( $t_cf_id );
	if( !custom_field_has_write_access_to_project( $t_cf_id, $f_target_project_id ) ) {
		continue;
	}
	if( gpc_isset_custom_field( $t_cf_id, $t_def['type'] ) ) {
		$t_value = gpc_get_custom_field( 'custom_field_' . $t_cf_id, $t_def['type'], null );
		$t_custom_fields[] = array(
			'field' => array( 'id' => (int)$t_cf_id ),
			'value' => $t_value,
		);
		$t_posted_ids[(int)$t_cf_id] = true;
	}
}

# Name-based / same-id fallback from the source ticket for fields not posted.
if( linked_issue_factory_config( 'copy_custom_fields_default', true ) ) {
	$t_source_fields = linked_issue_factory_collect_source_custom_fields( $f_bug_id );
	$t_mapped = linked_issue_factory_map_custom_fields( $t_source_fields, $f_target_project_id );
	foreach( $t_mapped as $t_entry ) {
		$t_id = (int)$t_entry['field']['id'];
		if( !isset( $t_posted_ids[$t_id] ) ) {
			$t_custom_fields[] = $t_entry;
			$t_posted_ids[$t_id] = true;
		}
	}
}

# ---------------------------------------------------------------------------
# Build the issue and create it.
# ---------------------------------------------------------------------------
$t_due_ts = 0;
if( !is_blank( $f_due_date_str ) ) {
	$t_parsed = strtotime( $f_due_date_str );
	if( $t_parsed !== false ) {
		$t_due_ts = $t_parsed;
	}
}

$t_issue = array(
	'target_project_id'      => $f_target_project_id,
	'summary'                => $f_summary,
	'description'            => $f_description,
	'steps_to_reproduce'     => $f_steps,
	'additional_information' => $f_additional,
	'category_id'            => $f_category_id,
	'priority'               => $f_priority,
	'severity'               => $f_severity,
	'reproducibility'        => $f_reproducibility,
	'handler_id'             => $f_handler_id,
	'view_state'             => $f_view_state,
	'due_date'               => $t_due_ts,
	'custom_fields'          => $t_custom_fields,
);

try {
	$t_new_bug_id = linked_issue_factory_create_linked_issue(
		$t_issue, $f_bug_id, $f_relationship_type );
} catch( ClientException $e ) {
	# Render a friendly error page instead of crashing; the most common cause is
	# a required custom field in the target project that could not be filled.
	form_security_purge( 'plugin_LinkedIssueFactory_create' );

	layout_page_header( plugin_lang_get( 'create_title' ) );
	layout_page_begin();
	echo '<div class="col-md-12 col-xs-12"><div class="space-10"></div>';
	echo '<div class="alert alert-danger">';
	echo '<p><strong>' . plugin_lang_get( 'error_create_failed' ) . '</strong></p>';
	echo '<p>' . string_display_line( $e->getMessage() ) . '</p>';
	echo '</div>';
	echo '<a class="btn btn-primary btn-white btn-round" href="'
		. string_attribute( plugin_page( 'create_related' ) . '&bug_id=' . $f_bug_id
			. '&target_project_id=' . $f_target_project_id ) . '">'
		. plugin_lang_get( 'btn_back' ) . '</a>';
	echo '</div>';
	layout_page_end();
	exit;
}

# Cross-reference notes in source + new ticket.
linked_issue_factory_add_link_notes( $f_bug_id, $t_new_bug_id, /* from_cron */ false );

form_security_purge( 'plugin_LinkedIssueFactory_create' );

print_header_redirect_view( $t_new_bug_id );
