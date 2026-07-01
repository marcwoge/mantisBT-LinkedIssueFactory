<?php
/**
 * LinkedIssueFactory – "create a linked ticket" form.
 *
 * Opened from the issue-view button (plugin.php?page=LinkedIssueFactory/create_related&bug_id=N).
 * Pre-fills the standard and custom fields from the source ticket and lets the
 * user pick a target project, relationship type and whether the ticket is
 * created once or scheduled to recur.
 *
 * All access control uses MantisBT core APIs; the actual creation happens in
 * create_related_action.php.
 *
 * @package LinkedIssueFactory
 * @license MIT
 */

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'custom_field_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();

$f_bug_id = gpc_get_int( 'bug_id' );

# The user must be allowed to view the source issue.
bug_ensure_exists( $f_bug_id );
access_ensure_bug_level( config_get( 'view_bug_threshold' ), $f_bug_id );

$t_source = bug_get( $f_bug_id, true );
$t_source_project = (int)$t_source->project_id;

# ---------------------------------------------------------------------------
# Determine the target project. Default: configured default, else the source
# project. The current project is overridden so that category / custom-field /
# handler lookups all resolve against the target project.
# ---------------------------------------------------------------------------
$t_default_target = (int)linked_issue_factory_config( 'default_target_project', 0 );
if( $t_default_target <= 0 ) {
	$t_default_target = $t_source_project;
}
$f_target_project_id = gpc_get_int( 'target_project_id', $t_default_target );

# Fall back to the source project when the requested target is not usable.
if( !project_exists( $f_target_project_id )
	|| !access_has_project_level( config_get( 'report_bug_threshold' ), $f_target_project_id ) ) {
	$f_target_project_id = access_has_project_level( config_get( 'report_bug_threshold' ), $t_source_project )
		? $t_source_project
		: 0;
}

# Override the "current" project so core option-list helpers use the target.
global $g_project_override;
if( $f_target_project_id > 0 ) {
	$g_project_override = $f_target_project_id;
}

$t_default_relationship = linked_issue_factory_default_relationship_type();
$t_relationship_types   = linked_issue_factory_relationship_types();
$t_recurring_enabled    = (bool)linked_issue_factory_config( 'enable_recurring', true );

layout_page_header( plugin_lang_get( 'create_title' ) );
layout_page_begin();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<?php
# When the user cannot report in any suitable project, stop early.
if( $f_target_project_id <= 0 ) {
?>
	<div class="alert alert-warning">
		<?php echo plugin_lang_get( 'error_no_report_access' ); ?>
	</div>
	</div>
<?php
	layout_page_end();
	exit;
}
?>

<!-- Target project chooser (separate GET form: changing it reloads the page
     so category / custom-field lists refresh for the chosen project). -->
<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<i class="ace-icon fa fa-code-fork"></i>
			<?php echo plugin_lang_get( 'create_title' ); ?>
			&mdash;
			<?php echo plugin_lang_get( 'source_issue' ); ?>
			<a href="<?php echo string_get_bug_view_url( $f_bug_id ); ?>"><?php echo bug_format_id( $f_bug_id ); ?></a>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">

	<form method="get" action="<?php echo plugin_page( 'create_related' ); ?>" class="form-inline">
		<input type="hidden" name="page" value="<?php echo string_attribute( plugin_get_current() . '/create_related' ); ?>" />
		<input type="hidden" name="bug_id" value="<?php echo $f_bug_id; ?>" />
		<label for="target_project_id"><?php echo plugin_lang_get( 'target_project' ); ?></label>
		<select id="target_project_id" name="target_project_id" class="input-sm"
			onchange="this.form.submit();">
			<?php # Only projects the user may report in. ?>
			<?php print_project_option_list( $f_target_project_id, false, null, false, true ); ?>
		</select>
		<noscript><input type="submit" class="btn btn-sm btn-primary" value="&raquo;" /></noscript>
	</form>

	</div>
	</div>
</div>

<!-- Main creation form -->
<form method="post" action="<?php echo plugin_page( 'create_related_action' ); ?>">
<?php echo form_security_field( 'plugin_LinkedIssueFactory_create' ); ?>
<input type="hidden" name="bug_id" value="<?php echo $f_bug_id; ?>" />
<input type="hidden" name="target_project_id" value="<?php echo $f_target_project_id; ?>" />

<div class="widget-box widget-color-blue2">
	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed">
		<tbody>

		<!-- Summary -->
		<tr>
			<th class="category" style="width:25%;">
				<span class="required">*</span>
				<label for="lif_summary"><?php echo plugin_lang_get( 'field_summary' ); ?></label>
			</th>
			<td>
				<input type="text" id="lif_summary" name="summary" class="input-sm" size="80"
					maxlength="128"
					value="<?php echo string_attribute( $t_source->summary ); ?>" />
			</td>
		</tr>

		<!-- Description -->
		<tr>
			<th class="category">
				<span class="required">*</span>
				<label for="lif_description"><?php echo plugin_lang_get( 'field_description' ); ?></label>
			</th>
			<td>
				<textarea id="lif_description" name="description" class="form-control"
					rows="6" cols="80"><?php echo string_textarea( $t_source->description ); ?></textarea>
			</td>
		</tr>

		<!-- Steps to reproduce -->
		<tr>
			<th class="category">
				<label for="lif_steps"><?php echo plugin_lang_get( 'field_steps' ); ?></label>
			</th>
			<td>
				<textarea id="lif_steps" name="steps_to_reproduce" class="form-control"
					rows="4" cols="80"><?php echo string_textarea( $t_source->steps_to_reproduce ); ?></textarea>
			</td>
		</tr>

		<!-- Additional information -->
		<tr>
			<th class="category">
				<label for="lif_addinfo"><?php echo plugin_lang_get( 'field_additional' ); ?></label>
			</th>
			<td>
				<textarea id="lif_addinfo" name="additional_information" class="form-control"
					rows="4" cols="80"><?php echo string_textarea( $t_source->additional_information ); ?></textarea>
			</td>
		</tr>

		<!-- Category -->
		<tr>
			<th class="category">
				<label for="lif_category"><?php echo plugin_lang_get( 'field_category' ); ?></label>
			</th>
			<td>
				<select id="lif_category" name="category_id" class="input-sm">
					<?php
					# Try to preselect the category with the same name as the
					# source category; print_category_option_list falls back to
					# the project default otherwise.
					$t_preselect_category = 0;
					$t_target_categories = category_get_all_rows( $f_target_project_id );
					$t_source_category_name = '';
					if( $t_source->category_id > 0 && category_exists( $t_source->category_id ) ) {
						$t_source_category_name = category_get_field( $t_source->category_id, 'name' );
					}
					foreach( $t_target_categories as $t_cat ) {
						if( $t_source_category_name !== '' && $t_cat['name'] === $t_source_category_name ) {
							$t_preselect_category = (int)$t_cat['id'];
							break;
						}
					}
					print_category_option_list( $t_preselect_category, $f_target_project_id );
					?>
				</select>
			</td>
		</tr>

		<!-- Priority -->
		<tr>
			<th class="category">
				<label for="lif_priority"><?php echo plugin_lang_get( 'field_priority' ); ?></label>
			</th>
			<td>
				<select id="lif_priority" name="priority" class="input-sm">
					<?php print_enum_string_option_list( 'priority', (int)$t_source->priority ); ?>
				</select>
			</td>
		</tr>

		<!-- Severity -->
		<tr>
			<th class="category">
				<label for="lif_severity"><?php echo plugin_lang_get( 'field_severity' ); ?></label>
			</th>
			<td>
				<select id="lif_severity" name="severity" class="input-sm">
					<?php print_enum_string_option_list( 'severity', (int)$t_source->severity ); ?>
				</select>
			</td>
		</tr>

		<!-- Reproducibility -->
		<tr>
			<th class="category">
				<label for="lif_repro"><?php echo plugin_lang_get( 'field_reproducibility' ); ?></label>
			</th>
			<td>
				<select id="lif_repro" name="reproducibility" class="input-sm">
					<?php print_enum_string_option_list( 'reproducibility', (int)$t_source->reproducibility ); ?>
				</select>
			</td>
		</tr>

		<!-- Handler / assignee (only if the user may assign in the target) -->
		<?php if( access_has_project_level( config_get( 'update_bug_assign_threshold' ), $f_target_project_id ) ) { ?>
		<tr>
			<th class="category">
				<label for="lif_handler"><?php echo plugin_lang_get( 'field_handler' ); ?></label>
			</th>
			<td>
				<select id="lif_handler" name="handler_id" class="input-sm">
					<?php
					# print_assign_to_option_list only lists users who may handle
					# issues in the target project, so an invalid handler cannot
					# be selected.
					$t_handler_preselect = ( $t_source->handler_id > 0
						&& access_has_project_level( config_get( 'handle_bug_threshold' ), $f_target_project_id, $t_source->handler_id ) )
						? (int)$t_source->handler_id : 0;
					print_assign_to_option_list( $t_handler_preselect, $f_target_project_id );
					?>
				</select>
			</td>
		</tr>
		<?php } ?>

		<!-- View state -->
		<tr>
			<th class="category">
				<label for="lif_view_state"><?php echo plugin_lang_get( 'field_view_state' ); ?></label>
			</th>
			<td>
				<select id="lif_view_state" name="view_state" class="input-sm">
					<?php print_enum_string_option_list( 'view_state', (int)$t_source->view_state ); ?>
				</select>
			</td>
		</tr>

		<!-- Due date -->
		<?php if( access_has_project_level( config_get( 'due_date_update_threshold' ), $f_target_project_id ) ) {
			$t_due_display = '';
			if( !date_is_null( $t_source->due_date ) ) {
				$t_due_display = date( config_get( 'normal_date_format' ), $t_source->due_date );
			}
		?>
		<tr>
			<th class="category">
				<label for="lif_due_date"><?php echo plugin_lang_get( 'field_due_date' ); ?></label>
			</th>
			<td>
				<input type="text" id="lif_due_date" name="due_date"
					class="datetimepicker input-sm" size="20" maxlength="16"
					data-picker-locale="<?php echo lang_get_current_datetime_locale(); ?>"
					data-picker-format="<?php echo config_get( 'datetime_picker_format' ); ?>"
					value="<?php echo string_attribute( $t_due_display ); ?>" />
			</td>
		</tr>
		<?php } ?>

		<!-- Relationship type -->
		<tr>
			<th class="category">
				<label for="lif_relation"><?php echo plugin_lang_get( 'field_relationship' ); ?></label>
			</th>
			<td>
				<select id="lif_relation" name="relationship_type" class="input-sm">
					<?php foreach( $t_relationship_types as $t_type_id => $t_label ) { ?>
						<option value="<?php echo (int)$t_type_id; ?>"
							<?php echo ( $t_type_id == $t_default_relationship ) ? 'selected="selected"' : ''; ?>>
							<?php echo string_display_line( $t_label ); ?>
						</option>
					<?php } ?>
				</select>
			</td>
		</tr>

		<?php
		# ---------------------------------------------------------------
		# Custom fields of the target project. Same-id fields are pre-filled
		# from the source ticket by print_custom_field_input(); required
		# fields are marked and must be completed before submitting.
		# ---------------------------------------------------------------
		$t_custom_field_ids = custom_field_get_linked_ids( $f_target_project_id );
		foreach( $t_custom_field_ids as $t_cf_id ) {
			$t_def = custom_field_get_definition( $t_cf_id );
			if( !( $t_def['display_report'] || $t_def['require_report'] ) ) {
				continue;
			}
			if( !custom_field_has_write_access_to_project( $t_cf_id, $f_target_project_id ) ) {
				continue;
			}
			# Pre-fill from the source bug only when the same field id is linked
			# to the source project too (otherwise use the field default).
			$t_prefill_bug_id = custom_field_is_linked( $t_cf_id, $t_source_project ) ? $f_bug_id : null;
		?>
		<tr>
			<th class="category">
				<?php if( $t_def['require_report'] ) { ?><span class="required">*</span><?php } ?>
				<?php echo string_display_line( lang_get_defaulted( $t_def['name'] ) ); ?>
			</th>
			<td>
				<?php print_custom_field_input( $t_def, $t_prefill_bug_id, (bool)$t_def['require_report'] ); ?>
			</td>
		</tr>
		<?php } ?>

		</tbody>
	</table>
	</div>
	</div>

	<div class="widget-toolbox padding-8 clearfix">
		<?php
		# "Create now" and "Plan recurrence" share one form and route to
		# different pages via the HTML5 formaction attribute, so the user's edits
		# carry over to whichever path they pick.
		?>
		<button type="submit" class="btn btn-primary btn-white btn-round"
			formaction="<?php echo plugin_page( 'create_related_action' ); ?>">
			<?php echo plugin_lang_get( 'btn_create' ); ?>
		</button>
		<?php if( $t_recurring_enabled ) { ?>
		<button type="submit" class="btn btn-white btn-round"
			formaction="<?php echo plugin_page( 'schedule_edit' ); ?>">
			<?php echo plugin_lang_get( 'btn_plan_recurrence' ); ?>
		</button>
		<span class="lighter">&mdash; <?php echo plugin_lang_get( 'mode_recurring_hint' ); ?></span>
		<?php } ?>
		<a class="btn btn-white btn-round" href="<?php echo string_get_bug_view_url( $f_bug_id ); ?>">
			<?php echo plugin_lang_get( 'btn_cancel' ); ?>
		</a>
	</div>
	</div>
</div>
</form>

</div>

<?php
layout_page_end();
