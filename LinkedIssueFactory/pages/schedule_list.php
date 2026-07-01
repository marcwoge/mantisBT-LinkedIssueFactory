<?php
/**
 * LinkedIssueFactory – schedule overview + management actions.
 *
 * Lists every recurring schedule (active and inactive) and offers
 * activate / deactivate / delete actions. Reachable via
 * Manage → Linked Issue Factory.
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
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

if( !linked_issue_factory_config( 'enable_recurring', true ) ) {
	access_denied();
}

$t_table = linked_issue_factory_schedule_table();

# ---------------------------------------------------------------------------
# Handle actions (activate / deactivate / delete).
# ---------------------------------------------------------------------------
$f_action = gpc_get_string( 'action', '' );
if( $f_action !== '' ) {
	form_security_validate( 'plugin_LinkedIssueFactory_schedule_list' );
	$f_id = gpc_get_int( 'id' );

	switch( $f_action ) {
		case 'activate':
		case 'deactivate':
			$t_active = ( $f_action === 'activate' ) ? 1 : 0;
			db_param_push();
			db_query( 'UPDATE ' . $t_table . ' SET active = ' . db_param()
				. ', updated_at = ' . db_param() . ' WHERE id = ' . db_param(),
				array( $t_active, db_now(), $f_id ) );
			break;
		case 'delete':
			db_param_push();
			db_query( 'DELETE FROM ' . $t_table . ' WHERE id = ' . db_param(),
				array( $f_id ) );
			break;
	}

	form_security_purge( 'plugin_LinkedIssueFactory_schedule_list' );
	print_successful_redirect( plugin_page( 'schedule_list', true ) );
	exit;
}

# ---------------------------------------------------------------------------
# Load all schedules.
# ---------------------------------------------------------------------------
$t_rows = array();
$t_result = db_query( 'SELECT * FROM ' . $t_table . ' ORDER BY active DESC, next_run_at ASC' );
while( $t_row = db_fetch_array( $t_result ) ) {
	$t_rows[] = $t_row;
}

$t_date_fmt = config_get( 'short_date_format' );
$t_rel_types = linked_issue_factory_relationship_types();

/**
 * Human-readable recurrence label for a schedule row.
 * @param array $p_row
 * @return string
 */
function lif_recurrence_label( array $p_row ) {
	$t_type = $p_row['recurrence_type'];
	if( $t_type === 'interval' ) {
		return sprintf( '%s %d %s',
			plugin_lang_get( 'field_every' ),
			(int)$p_row['recurrence_interval'],
			plugin_lang_get( 'unit_' . $p_row['recurrence_unit'] ) );
	}
	return plugin_lang_get( 'recurrence_' . $t_type );
}

layout_page_header( plugin_lang_get( 'schedule_list_title' ) );
layout_page_begin();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<i class="ace-icon fa fa-list"></i>
			<?php echo plugin_lang_get( 'schedule_list_title' ); ?>
			<span class="badge"><?php echo count( $t_rows ); ?></span>
		</h4>
	</div>

	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed table-striped">
		<thead>
			<tr>
				<th><?php echo plugin_lang_get( 'col_id' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_source' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_target_project' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_summary' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_recurrence' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_next_run' ); ?></th>
				<th class="center"><?php echo plugin_lang_get( 'col_occurrences' ); ?></th>
				<th class="center"><?php echo plugin_lang_get( 'col_active' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_actions' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach( $t_rows as $t_row ) {
			$t_id = (int)$t_row['id'];
			$t_max = (int)$t_row['max_occurrences'];
			$t_occ = (int)$t_row['occurrences_created'] . ( $t_max > 0 ? ' / ' . $t_max : '' );
		?>
			<tr<?php echo $t_row['active'] ? '' : ' class="text-muted"'; ?>>
				<td><?php echo $t_id; ?></td>
				<td>
					<a href="<?php echo string_get_bug_view_url( (int)$t_row['source_bug_id'] ); ?>">
						<?php echo bug_format_id( (int)$t_row['source_bug_id'] ); ?>
					</a>
				</td>
				<td><?php echo string_display_line( project_get_name( (int)$t_row['target_project_id'], false ) ); ?></td>
				<td><?php echo string_display_line( $t_row['summary_template'] ); ?></td>
				<td><?php echo string_display_line( lif_recurrence_label( $t_row ) ); ?></td>
				<td><?php echo (int)$t_row['next_run_at'] > 0 ? date( $t_date_fmt, (int)$t_row['next_run_at'] ) : '&mdash;'; ?></td>
				<td class="center"><?php echo string_display_line( $t_occ ); ?></td>
				<td class="center">
					<?php if( $t_row['active'] ) { ?>
						<span class="label label-success"><?php echo plugin_lang_get( 'state_active' ); ?></span>
					<?php } else { ?>
						<span class="label label-default"><?php echo plugin_lang_get( 'state_inactive' ); ?></span>
					<?php } ?>
				</td>
				<td>
					<a class="btn btn-xs btn-primary btn-white"
						href="<?php echo string_attribute( plugin_page( 'schedule_edit' ) . '&schedule_id=' . $t_id ); ?>">
						<?php echo plugin_lang_get( 'action_edit' ); ?>
					</a>
					<form method="post" action="<?php echo plugin_page( 'schedule_list' ); ?>" style="display:inline;">
						<?php echo form_security_field( 'plugin_LinkedIssueFactory_schedule_list' ); ?>
						<input type="hidden" name="id" value="<?php echo $t_id; ?>" />
						<?php if( $t_row['active'] ) { ?>
							<input type="hidden" name="action" value="deactivate" />
							<button type="submit" class="btn btn-xs btn-white"><?php echo plugin_lang_get( 'action_deactivate' ); ?></button>
						<?php } else { ?>
							<input type="hidden" name="action" value="activate" />
							<button type="submit" class="btn btn-xs btn-white"><?php echo plugin_lang_get( 'action_activate' ); ?></button>
						<?php } ?>
					</form>
					<form method="post" action="<?php echo plugin_page( 'schedule_list' ); ?>" style="display:inline;"
						onsubmit="return confirm('<?php echo string_attribute( plugin_lang_get( 'confirm_delete' ) ); ?>');">
						<?php echo form_security_field( 'plugin_LinkedIssueFactory_schedule_list' ); ?>
						<input type="hidden" name="id" value="<?php echo $t_id; ?>" />
						<input type="hidden" name="action" value="delete" />
						<button type="submit" class="btn btn-xs btn-danger btn-white"><?php echo plugin_lang_get( 'action_delete' ); ?></button>
					</form>
				</td>
			</tr>
		<?php } ?>
		<?php if( empty( $t_rows ) ) { ?>
			<tr><td colspan="9" class="center"><?php echo plugin_lang_get( 'no_schedules' ); ?></td></tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	</div>
</div>
</div>

<?php
layout_page_end();
