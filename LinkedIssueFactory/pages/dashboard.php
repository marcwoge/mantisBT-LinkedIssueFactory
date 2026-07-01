<?php
/**
 * LinkedIssueFactory – dashboard of upcoming scheduled creations.
 *
 * Lists the active schedules ordered by their next creation date, colour-coded
 * by how soon the next ticket will be generated. Reachable via
 * Manage → Linked Issue Factory.
 *
 * @package LinkedIssueFactory
 * @license MIT
 */

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'config_api.php' );
require_api( 'database_api.php' );
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
$t_date_fmt = config_get( 'short_date_format' );

$t_rows = array();
$t_result = db_query( 'SELECT * FROM ' . $t_table
	. ' WHERE active = ' . db_param() . ' ORDER BY next_run_at ASC', array( 1 ) );
while( $t_row = db_fetch_array( $t_result ) ) {
	$t_rows[] = $t_row;
}

$t_today = strtotime( 'today' );

/**
 * Row colour based on days until the next creation.
 * @param int|null $p_days_left
 * @return string
 */
function lif_dashboard_color( $p_days_left ) {
	if( $p_days_left === null ) {
		return '#f5f5f5';
	}
	if( $p_days_left <= 0 ) {
		return '#f8d7da'; // due now / overdue
	}
	if( $p_days_left < 7 ) {
		return '#ffe5cc'; // this week
	}
	if( $p_days_left < 30 ) {
		return '#fff3cd'; // this month
	}
	return '#d4edda'; // further out
}

layout_page_header( plugin_lang_get( 'dashboard_title' ) );
layout_page_begin();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<i class="ace-icon fa fa-calendar"></i>
			<?php echo plugin_lang_get( 'dashboard_title' ); ?>
			<span class="badge"><?php echo count( $t_rows ); ?></span>
		</h4>
	</div>

	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed table-hover">
		<thead>
			<tr>
				<th><?php echo plugin_lang_get( 'col_next_run' ); ?></th>
				<th class="center"><?php echo plugin_lang_get( 'col_days_left' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_source' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_target_project' ); ?></th>
				<th><?php echo plugin_lang_get( 'col_summary' ); ?></th>
				<th class="center"><?php echo plugin_lang_get( 'col_occurrences' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach( $t_rows as $t_row ) {
			$t_next = (int)$t_row['next_run_at'];
			$t_days_left = $t_next > 0 ? (int)floor( ( $t_next - $t_today ) / 86400 ) : null;
			$t_color = lif_dashboard_color( $t_days_left );
			$t_max = (int)$t_row['max_occurrences'];
			$t_occ = (int)$t_row['occurrences_created'] . ( $t_max > 0 ? ' / ' . $t_max : '' );
		?>
			<tr style="background-color:<?php echo $t_color; ?>;">
				<td><?php echo $t_next > 0 ? date( $t_date_fmt, $t_next ) : '&mdash;'; ?></td>
				<td class="center"><?php echo $t_days_left === null ? '&mdash;' : $t_days_left; ?></td>
				<td>
					<a href="<?php echo string_get_bug_view_url( (int)$t_row['source_bug_id'] ); ?>">
						<?php echo bug_format_id( (int)$t_row['source_bug_id'] ); ?>
					</a>
				</td>
				<td><?php echo string_display_line( project_get_name( (int)$t_row['target_project_id'], false ) ); ?></td>
				<td><?php echo string_display_line( $t_row['summary_template'] ); ?></td>
				<td class="center"><?php echo string_display_line( $t_occ ); ?></td>
			</tr>
		<?php } ?>
		<?php if( empty( $t_rows ) ) { ?>
			<tr><td colspan="6" class="center"><?php echo plugin_lang_get( 'no_upcoming' ); ?></td></tr>
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
