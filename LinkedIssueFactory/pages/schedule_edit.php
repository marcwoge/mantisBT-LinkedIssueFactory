<?php
/**
 * LinkedIssueFactory – create or edit a recurring schedule.
 *
 * Reached either from the "Plan recurrence" button on the create-linked-ticket
 * form (a new schedule, template fields pre-filled from the posted values) or
 * from the schedule list (editing an existing row). Only renders the form;
 * persistence happens in schedule_save.php.
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

auth_ensure_user_authenticated();

if( !linked_issue_factory_config( 'enable_recurring', true ) ) {
	access_denied();
}

$f_schedule_id = gpc_get_int( 'schedule_id', 0 );

# Defaults for a brand-new schedule.
$t_row = array(
	'id'                              => 0,
	'source_bug_id'                   => 0,
	'target_project_id'               => 0,
	'relation_type'                   => linked_issue_factory_default_relationship_type(),
	'summary_template'                => '',
	'description_template'            => '',
	'steps_to_reproduce_template'     => '',
	'additional_information_template' => '',
	'copy_standard_fields'            => 1,
	'copy_custom_fields'              => (int)linked_issue_factory_config( 'copy_custom_fields_default', 1 ),
	'custom_field_strategy'           => 'linked_or_name',
	'recurrence_type'                 => linked_issue_factory_config( 'default_recurrence_type', 'monthly' ),
	'recurrence_interval'             => 1,
	'recurrence_unit'                 => 'days',
	'start_at'                        => strtotime( 'today' ),
	'end_at'                          => 0,
	'max_occurrences'                 => 0,
	'active'                          => 1,
);

if( $f_schedule_id > 0 ) {
	# ---- Editing an existing schedule -------------------------------------
	$t_table = linked_issue_factory_schedule_table();
	db_param_push();
	$t_result = db_query(
		'SELECT * FROM ' . $t_table . ' WHERE id = ' . db_param(),
		array( $f_schedule_id ) );
	$t_db_row = db_fetch_array( $t_result );
	if( !$t_db_row ) {
		trigger_error( ERROR_GENERIC, ERROR );
	}
	$t_row = array_merge( $t_row, $t_db_row );
	$t_source_bug_id     = (int)$t_row['source_bug_id'];
	$t_target_project_id = (int)$t_row['target_project_id'];
} else {
	# ---- New schedule from a source ticket --------------------------------
	$t_source_bug_id = gpc_get_int( 'bug_id' );
	bug_ensure_exists( $t_source_bug_id );
	access_ensure_bug_level( config_get( 'view_bug_threshold' ), $t_source_bug_id );

	$t_source = bug_get( $t_source_bug_id, true );

	$t_target_project_id = gpc_get_int( 'target_project_id', (int)$t_source->project_id );

	# Template fields: prefer posted values (arriving from the create form),
	# otherwise fall back to the source ticket's text.
	$t_row['source_bug_id']                   = $t_source_bug_id;
	$t_row['target_project_id']               = $t_target_project_id;
	$t_row['relation_type']                   = gpc_get_int( 'relationship_type', $t_row['relation_type'] );
	$t_row['summary_template']                = gpc_get_string( 'summary', $t_source->summary );
	$t_row['description_template']            = gpc_get_string( 'description', $t_source->description );
	$t_row['steps_to_reproduce_template']     = gpc_get_string( 'steps_to_reproduce', $t_source->steps_to_reproduce );
	$t_row['additional_information_template'] = gpc_get_string( 'additional_information', $t_source->additional_information );
}

# The user must be allowed to report in the target project.
if( !project_exists( $t_target_project_id )
	|| !access_has_project_level( config_get( 'report_bug_threshold' ), $t_target_project_id ) ) {
	access_denied();
}

$t_relationship_types = linked_issue_factory_relationship_types();

# Small helper for date inputs.
$t_date_fmt = config_get( 'normal_date_format' );
function lif_date_value( $p_ts, $p_fmt ) {
	$p_ts = (int)$p_ts;
	return $p_ts > 0 ? date( $p_fmt, $p_ts ) : '';
}

layout_page_header( plugin_lang_get( 'schedule_edit_title' ) );
layout_page_begin();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<form method="post" action="<?php echo plugin_page( 'schedule_save' ); ?>">
<?php echo form_security_field( 'plugin_LinkedIssueFactory_schedule_save' ); ?>
<input type="hidden" name="schedule_id" value="<?php echo (int)$t_row['id']; ?>" />
<input type="hidden" name="source_bug_id" value="<?php echo (int)$t_source_bug_id; ?>" />
<input type="hidden" name="target_project_id" value="<?php echo (int)$t_target_project_id; ?>" />

<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<i class="ace-icon fa fa-refresh"></i>
			<?php echo plugin_lang_get( 'schedule_edit_title' ); ?>
			&mdash; <?php echo plugin_lang_get( 'source_issue' ); ?>
			<a href="<?php echo string_get_bug_view_url( $t_source_bug_id ); ?>"><?php echo bug_format_id( $t_source_bug_id ); ?></a>
			&rarr; <?php echo string_display_line( project_get_name( $t_target_project_id ) ); ?>
		</h4>
	</div>

	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed">
		<tbody>

		<tr>
			<th class="category" style="width:25%;"><?php echo plugin_lang_get( 'field_relationship' ); ?></th>
			<td>
				<select name="relation_type" class="input-sm">
					<?php foreach( $t_relationship_types as $t_type_id => $t_label ) { ?>
						<option value="<?php echo (int)$t_type_id; ?>"
							<?php echo ( $t_type_id == $t_row['relation_type'] ) ? 'selected="selected"' : ''; ?>>
							<?php echo string_display_line( $t_label ); ?>
						</option>
					<?php } ?>
				</select>
			</td>
		</tr>

		<tr>
			<th class="category"><span class="required">*</span><?php echo plugin_lang_get( 'field_summary' ); ?></th>
			<td><input type="text" name="summary_template" class="input-sm" size="80" maxlength="128"
				value="<?php echo string_attribute( $t_row['summary_template'] ); ?>" /></td>
		</tr>

		<tr>
			<th class="category"><span class="required">*</span><?php echo plugin_lang_get( 'field_description' ); ?></th>
			<td><textarea name="description_template" class="form-control" rows="5" cols="80"><?php echo string_textarea( $t_row['description_template'] ); ?></textarea></td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_steps' ); ?></th>
			<td><textarea name="steps_to_reproduce_template" class="form-control" rows="3" cols="80"><?php echo string_textarea( $t_row['steps_to_reproduce_template'] ); ?></textarea></td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_additional' ); ?></th>
			<td><textarea name="additional_information_template" class="form-control" rows="3" cols="80"><?php echo string_textarea( $t_row['additional_information_template'] ); ?></textarea></td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_copy_standard' ); ?></th>
			<td>
				<label><input type="checkbox" name="copy_standard_fields" value="1"
					<?php echo $t_row['copy_standard_fields'] ? 'checked="checked"' : ''; ?> />
					<?php echo plugin_lang_get( 'field_copy_standard_hint' ); ?></label>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_copy_custom' ); ?></th>
			<td>
				<label><input type="checkbox" name="copy_custom_fields" value="1"
					<?php echo $t_row['copy_custom_fields'] ? 'checked="checked"' : ''; ?> />
					<?php echo plugin_lang_get( 'field_copy_custom_hint' ); ?></label>
				<div class="space-4"></div>
				<select name="custom_field_strategy" class="input-sm">
					<option value="linked_or_name" <?php echo $t_row['custom_field_strategy'] === 'linked_or_name' ? 'selected="selected"' : ''; ?>>
						<?php echo plugin_lang_get( 'cf_strategy_linked_or_name' ); ?>
					</option>
					<option value="linked_only" <?php echo $t_row['custom_field_strategy'] === 'linked_only' ? 'selected="selected"' : ''; ?>>
						<?php echo plugin_lang_get( 'cf_strategy_linked_only' ); ?>
					</option>
				</select>
			</td>
		</tr>

		<!-- Recurrence -->
		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_recurrence_type' ); ?></th>
			<td>
				<select name="recurrence_type" id="lif_rec_type" class="input-sm">
					<?php foreach( linked_issue_factory_recurrence_types() as $t_rt ) { ?>
						<option value="<?php echo $t_rt; ?>" <?php echo $t_row['recurrence_type'] === $t_rt ? 'selected="selected"' : ''; ?>>
							<?php echo plugin_lang_get( 'recurrence_' . $t_rt ); ?>
						</option>
					<?php } ?>
				</select>
				<span id="lif_interval_box">
					&mdash; <?php echo plugin_lang_get( 'field_every' ); ?>
					<input type="number" name="recurrence_interval" min="1" style="width:5em;"
						class="input-sm" value="<?php echo (int)$t_row['recurrence_interval']; ?>" />
					<select name="recurrence_unit" class="input-sm">
						<?php foreach( linked_issue_factory_recurrence_units() as $t_unit ) { ?>
							<option value="<?php echo $t_unit; ?>" <?php echo $t_row['recurrence_unit'] === $t_unit ? 'selected="selected"' : ''; ?>>
								<?php echo plugin_lang_get( 'unit_' . $t_unit ); ?>
							</option>
						<?php } ?>
					</select>
				</span>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_start_at' ); ?></th>
			<td><input type="text" name="start_at" class="datetimepicker input-sm" size="16" maxlength="16"
				data-picker-locale="<?php echo lang_get_current_datetime_locale(); ?>"
				data-picker-format="<?php echo config_get( 'datetime_picker_format' ); ?>"
				value="<?php echo string_attribute( lif_date_value( $t_row['start_at'], $t_date_fmt ) ); ?>" /></td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_end_at' ); ?></th>
			<td>
				<input type="text" name="end_at" class="datetimepicker input-sm" size="16" maxlength="16"
					data-picker-locale="<?php echo lang_get_current_datetime_locale(); ?>"
					data-picker-format="<?php echo config_get( 'datetime_picker_format' ); ?>"
					value="<?php echo string_attribute( lif_date_value( $t_row['end_at'], $t_date_fmt ) ); ?>" />
				<span class="lighter"><?php echo plugin_lang_get( 'field_optional' ); ?></span>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_max_occurrences' ); ?></th>
			<td>
				<input type="number" name="max_occurrences" min="0" style="width:6em;" class="input-sm"
					value="<?php echo (int)$t_row['max_occurrences']; ?>" />
				<span class="lighter"><?php echo plugin_lang_get( 'field_max_occurrences_hint' ); ?></span>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'field_active' ); ?></th>
			<td>
				<label><input type="checkbox" name="active" value="1"
					<?php echo $t_row['active'] ? 'checked="checked"' : ''; ?> />
					<?php echo plugin_lang_get( 'field_active_hint' ); ?></label>
			</td>
		</tr>

		</tbody>
	</table>
	</div>
	</div>

	<div class="widget-toolbox padding-8 clearfix">
		<input type="submit" class="btn btn-primary btn-white btn-round"
			value="<?php echo plugin_lang_get( 'btn_save_schedule' ); ?>" />
		<a class="btn btn-white btn-round" href="<?php echo plugin_page( 'schedule_list' ); ?>">
			<?php echo plugin_lang_get( 'btn_cancel' ); ?>
		</a>
	</div>
	</div>
</div>
</form>
</div>

<script type="text/javascript">
/* Show the "every X unit" box only for the free-form "interval" type. */
(function () {
	var sel = document.getElementById( 'lif_rec_type' );
	var box = document.getElementById( 'lif_interval_box' );
	function toggle() { box.style.display = ( sel.value === 'interval' ) ? '' : 'none'; }
	sel.addEventListener( 'change', toggle );
	toggle();
})();
</script>

<?php
layout_page_end();
