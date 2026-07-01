<?php
/**
 * LinkedIssueFactory – plugin configuration page.
 *
 * Reachable via Manage → Linked Issue Factory → Configuration. Persists the
 * plugin defaults through plugin_config_set() (i.e. into the ordinary MantisBT
 * config table), which is why the CLI cron runner can read them.
 *
 * @package LinkedIssueFactory
 * @license MIT
 */

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );

auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

# ---------------------------------------------------------------------------
# Save action.
# ---------------------------------------------------------------------------
$f_action = gpc_get_string( 'action', '' );
if( $f_action === 'update' ) {
	form_security_validate( 'plugin_LinkedIssueFactory_config' );

	$t_rel_type = gpc_get_int( 'default_relationship_type', BUG_RELATED );
	if( !linked_issue_factory_relationship_is_valid( $t_rel_type ) ) {
		$t_rel_type = BUG_RELATED;
	}

	$t_recurrence = gpc_get_string( 'default_recurrence_type', 'monthly' );
	if( !in_array( $t_recurrence, linked_issue_factory_recurrence_types(), true ) ) {
		$t_recurrence = 'monthly';
	}

	$t_link_target = gpc_get_string( 'link_target', 'source' );
	if( !in_array( $t_link_target, array( 'source', 'last' ), true ) ) {
		$t_link_target = 'source';
	}

	plugin_config_set( 'default_relationship_type', $t_rel_type );
	plugin_config_set( 'copy_custom_fields_default', gpc_get_bool( 'copy_custom_fields_default', false ) ? 1 : 0 );
	plugin_config_set( 'default_target_project', gpc_get_int( 'default_target_project', 0 ) );
	plugin_config_set( 'enable_recurring', gpc_get_bool( 'enable_recurring', false ) ? 1 : 0 );
	plugin_config_set( 'cron_user', gpc_get_string( 'cron_user', '' ) );
	plugin_config_set( 'default_recurrence_type', $t_recurrence );
	plugin_config_set( 'add_internal_notes', gpc_get_bool( 'add_internal_notes', false ) ? 1 : 0 );
	plugin_config_set( 'link_target', $t_link_target );
	plugin_config_set( 'notes_private', gpc_get_bool( 'notes_private', false ) ? 1 : 0 );

	form_security_purge( 'plugin_LinkedIssueFactory_config' );
	print_successful_redirect( plugin_page( 'config', true ) );
	exit;
}

# ---------------------------------------------------------------------------
# Current values.
# ---------------------------------------------------------------------------
$t_rel_type       = linked_issue_factory_default_relationship_type();
$t_copy_cf        = (bool)linked_issue_factory_config( 'copy_custom_fields_default', true );
$t_default_target = (int)linked_issue_factory_config( 'default_target_project', 0 );
$t_enable_rec     = (bool)linked_issue_factory_config( 'enable_recurring', true );
$t_cron_user      = (string)linked_issue_factory_config( 'cron_user', '' );
$t_default_rec    = (string)linked_issue_factory_config( 'default_recurrence_type', 'monthly' );
$t_add_notes      = (bool)linked_issue_factory_config( 'add_internal_notes', true );
$t_link_target    = (string)linked_issue_factory_config( 'link_target', 'source' );
$t_notes_private  = (bool)linked_issue_factory_config( 'notes_private', true );

$t_rel_types = linked_issue_factory_relationship_types();

layout_page_header( plugin_lang_get( 'config_title' ) );
layout_page_begin();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<form action="<?php echo plugin_page( 'config' ); ?>" method="post">
<?php echo form_security_field( 'plugin_LinkedIssueFactory_config' ); ?>
<input type="hidden" name="action" value="update" />

<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<i class="ace-icon fa fa-cogs"></i>
			<?php echo plugin_lang_get( 'config_title' ); ?>
		</h4>
	</div>

	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed">
		<tbody>

		<tr>
			<th class="category" style="width:35%;"><?php echo plugin_lang_get( 'cfg_default_relationship' ); ?></th>
			<td>
				<select name="default_relationship_type" class="input-sm">
					<?php foreach( $t_rel_types as $t_id => $t_label ) { ?>
						<option value="<?php echo (int)$t_id; ?>" <?php echo ( $t_id == $t_rel_type ) ? 'selected="selected"' : ''; ?>>
							<?php echo string_display_line( $t_label ); ?>
						</option>
					<?php } ?>
				</select>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_copy_custom_default' ); ?></th>
			<td>
				<label><input type="checkbox" name="copy_custom_fields_default" value="1"
					<?php echo $t_copy_cf ? 'checked="checked"' : ''; ?> />
					<?php echo plugin_lang_get( 'cfg_copy_custom_default_hint' ); ?></label>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_default_target' ); ?></th>
			<td>
				<select name="default_target_project" class="input-sm">
					<option value="0" <?php echo ( $t_default_target === 0 ) ? 'selected="selected"' : ''; ?>>
						<?php echo plugin_lang_get( 'cfg_default_target_none' ); ?>
					</option>
					<?php print_project_option_list( $t_default_target, false ); ?>
				</select>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_enable_recurring' ); ?></th>
			<td>
				<label><input type="checkbox" name="enable_recurring" value="1"
					<?php echo $t_enable_rec ? 'checked="checked"' : ''; ?> />
					<?php echo plugin_lang_get( 'cfg_enable_recurring_hint' ); ?></label>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_default_recurrence' ); ?></th>
			<td>
				<select name="default_recurrence_type" class="input-sm">
					<?php foreach( linked_issue_factory_recurrence_types() as $t_rt ) { ?>
						<option value="<?php echo $t_rt; ?>" <?php echo ( $t_default_rec === $t_rt ) ? 'selected="selected"' : ''; ?>>
							<?php echo plugin_lang_get( 'recurrence_' . $t_rt ); ?>
						</option>
					<?php } ?>
				</select>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_cron_user' ); ?></th>
			<td>
				<input type="text" name="cron_user" class="input-sm" size="30"
					value="<?php echo string_attribute( $t_cron_user ); ?>" />
				<span class="lighter"><?php echo plugin_lang_get( 'cfg_cron_user_hint' ); ?></span>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_link_target' ); ?></th>
			<td>
				<select name="link_target" class="input-sm">
					<option value="source" <?php echo ( $t_link_target === 'source' ) ? 'selected="selected"' : ''; ?>>
						<?php echo plugin_lang_get( 'cfg_link_target_source' ); ?>
					</option>
					<option value="last" <?php echo ( $t_link_target === 'last' ) ? 'selected="selected"' : ''; ?>>
						<?php echo plugin_lang_get( 'cfg_link_target_last' ); ?>
					</option>
				</select>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_add_notes' ); ?></th>
			<td>
				<label><input type="checkbox" name="add_internal_notes" value="1"
					<?php echo $t_add_notes ? 'checked="checked"' : ''; ?> />
					<?php echo plugin_lang_get( 'cfg_add_notes_hint' ); ?></label>
			</td>
		</tr>

		<tr>
			<th class="category"><?php echo plugin_lang_get( 'cfg_notes_private' ); ?></th>
			<td>
				<label><input type="checkbox" name="notes_private" value="1"
					<?php echo $t_notes_private ? 'checked="checked"' : ''; ?> />
					<?php echo plugin_lang_get( 'cfg_notes_private_hint' ); ?></label>
			</td>
		</tr>

		</tbody>
	</table>
	</div>
	</div>

	<div class="widget-toolbox padding-8 clearfix">
		<input type="submit" class="btn btn-primary btn-white btn-round"
			value="<?php echo plugin_lang_get( 'btn_save' ); ?>" />
	</div>
	</div>
</div>
</form>
</div>

<?php
layout_page_end();
