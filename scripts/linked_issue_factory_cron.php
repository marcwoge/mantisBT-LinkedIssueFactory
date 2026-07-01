<?php
/**
 * LinkedIssueFactory – CLI cron runner.
 *
 * Walks every active recurring schedule whose next_run_at has come due and
 * creates the linked ticket from the stored template snapshot. Follow-up runs
 * are scheduled by advancing next_run_at; schedules that have reached their end
 * date or maximum number of occurrences are deactivated.
 *
 * Usage:
 *   php plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php [options]
 *
 *   --dry-run            Show what would happen without changing anything.
 *   --schedule-id=<id>   Only process the given schedule id (for testing).
 *   --verbose            Log skipped / not-yet-due schedules as well.
 *   --help, -h           Show this help.
 *
 * The runner logs in as a technical MantisBT user:
 *   1. environment variable LINKED_ISSUE_FACTORY_USER, else
 *   2. the plugin's configured "cron_user", else
 *   3. "administrator".
 *
 * Recommended crontab entry (every day at 06:00):
 *   0 6 * * * php /path/to/mantis/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php
 *
 * Concurrency: each due schedule is claimed with an atomic, conditional UPDATE
 * of next_run_at, so two runners started at the same time can never create a
 * duplicate ticket for the same occurrence.
 *
 * @package LinkedIssueFactory
 * @license MIT
 */

use Mantis\Exceptions\ClientException;

# ---------------------------------------------------------------------------
# CLI guard – refuse to run from a web request.
# ---------------------------------------------------------------------------
if( php_sapi_name() !== 'cli' ) {
	http_response_code( 403 );
	die( "linked_issue_factory_cron.php must be run from the command line.\n" );
}

# ---------------------------------------------------------------------------
# Argument parsing.
# ---------------------------------------------------------------------------
$g_dry_run     = false;
$g_verbose     = false;
$g_schedule_id = 0;

foreach( $argv as $t_i => $t_arg ) {
	if( $t_i === 0 ) {
		continue; # script name
	}
	if( $t_arg === '--dry-run' ) {
		$g_dry_run = true;
	} elseif( $t_arg === '--verbose' ) {
		$g_verbose = true;
	} elseif( strpos( $t_arg, '--schedule-id=' ) === 0 ) {
		$g_schedule_id = (int)substr( $t_arg, strlen( '--schedule-id=' ) );
	} elseif( $t_arg === '--help' || $t_arg === '-h' ) {
		echo "LinkedIssueFactory cron runner\n";
		echo "Usage: php linked_issue_factory_cron.php [options]\n";
		echo "  --dry-run           Show what would happen without changing anything.\n";
		echo "  --schedule-id=<id>  Only process the given schedule id.\n";
		echo "  --verbose           Also log skipped / not-yet-due schedules.\n";
		echo "  --help, -h          Show this help.\n";
		exit( 0 );
	} else {
		fwrite( STDERR, "Unknown argument: $t_arg\n" );
		exit( 2 );
	}
}

# ---------------------------------------------------------------------------
# Bootstrap MantisBT core. The script lives in
#   <mantis>/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php
# so the MantisBT root is three directories up.
# ---------------------------------------------------------------------------
require_once( dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR . 'core.php' );

# Pull in the shared helper library (the main plugin class file is NOT loaded
# in CLI context, but the library only depends on core APIs).
require_once( dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'core'
	. DIRECTORY_SEPARATOR . 'linked_issue_factory_api.php' );

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'config_api.php' );
require_api( 'database_api.php' );
require_api( 'user_api.php' );

/* -------------------------------------------------------------------------- *
 *  Self-contained logging helper.
 * -------------------------------------------------------------------------- */

/**
 * Logs a message to stdout with an ISO timestamp.
 * @param string $p_message
 * @return void
 */
function lif_cron_log( $p_message ) {
	echo '[' . date( 'Y-m-d H:i:s' ) . '] ' . $p_message . "\n";
}

/**
 * Logs only when --verbose was given.
 * @param string $p_message
 * @return void
 */
function lif_cron_debug( $p_message ) {
	global $g_verbose;
	if( $g_verbose ) {
		lif_cron_log( $p_message );
	}
}

# ---------------------------------------------------------------------------
# Login as the technical user (skipped in dry-run since we only read).
# ---------------------------------------------------------------------------
lif_cron_log( 'LinkedIssueFactory cron started'
	. ( $g_dry_run ? ' (DRY-RUN)' : '' )
	. ( $g_schedule_id > 0 ? ' [schedule-id=' . $g_schedule_id . ']' : '' ) . '.' );

if( !linked_issue_factory_config( 'enable_recurring', true ) ) {
	lif_cron_log( 'Recurring tickets are disabled in the plugin configuration. Nothing to do.' );
	exit( 0 );
}

if( !$g_dry_run ) {
	$t_script_user = getenv( 'LINKED_ISSUE_FACTORY_USER' );
	if( $t_script_user === false || $t_script_user === '' ) {
		$t_script_user = (string)linked_issue_factory_config( 'cron_user', '' );
	}
	if( $t_script_user === '' ) {
		$t_script_user = 'administrator';
	}
	if( !auth_attempt_script_login( $t_script_user ) ) {
		lif_cron_log( 'ERROR: could not log in as "' . $t_script_user
			. '". Set LINKED_ISSUE_FACTORY_USER (or the plugin cron_user) to a valid account.' );
		exit( 1 );
	}
	lif_cron_debug( 'Logged in as "' . $t_script_user . '".' );
}

# ---------------------------------------------------------------------------
# Fetch candidate schedules.
# ---------------------------------------------------------------------------
$t_table = linked_issue_factory_schedule_table();
$t_now   = time();

$t_sql = 'SELECT * FROM ' . $t_table . ' WHERE active = ' . db_param()
	. ' AND next_run_at > ' . db_param() . ' AND next_run_at <= ' . db_param();
$t_params = array( 1, 0, $t_now );
if( $g_schedule_id > 0 ) {
	$t_sql .= ' AND id = ' . db_param();
	$t_params[] = $g_schedule_id;
}
$t_sql .= ' ORDER BY id';

db_param_push();
$t_result = db_query( $t_sql, $t_params );

$t_examined = 0;
$t_created  = 0;
$t_skipped  = 0;
$t_deactivated = 0;

while( $t_row = db_fetch_array( $t_result ) ) {
	$t_examined++;
	$t_id            = (int)$t_row['id'];
	$t_old_next      = (int)$t_row['next_run_at'];
	$t_source_bug_id = (int)$t_row['source_bug_id'];
	$t_target_pid    = (int)$t_row['target_project_id'];
	$t_occ_created   = (int)$t_row['occurrences_created'];
	$t_max_occ       = (int)$t_row['max_occurrences'];
	$t_end_at        = (int)$t_row['end_at'];

	lif_cron_debug( sprintf( 'Schedule #%d due (next_run_at %s).',
		$t_id, date( 'Y-m-d H:i:s', $t_old_next ) ) );

	# ---- Compute the follow-up run date -----------------------------------
	$t_new_next = linked_issue_factory_compute_next_run(
		$t_old_next,
		$t_row['recurrence_type'],
		(int)$t_row['recurrence_interval'],
		$t_row['recurrence_unit'],
		$t_now
	);
	# null => "once": no follow-up. Store 0 and deactivate after creation.
	$t_claimed_next = ( $t_new_next === null ) ? 0 : (int)$t_new_next;

	# Determine whether this is the last occurrence.
	$t_will_end = false;
	if( $t_new_next === null ) {
		$t_will_end = true; # "once"
	}
	if( $t_max_occ > 0 && ( $t_occ_created + 1 ) >= $t_max_occ ) {
		$t_will_end = true; # reached max occurrences
	}
	if( $t_end_at > 0 && $t_claimed_next > $t_end_at ) {
		$t_will_end = true; # next run would be past the end date
	}

	if( $g_dry_run ) {
		lif_cron_log( sprintf(
			'  Schedule #%d: WOULD create a ticket in project %d from source #%d'
			. ' (relation %d).%s',
			$t_id, $t_target_pid, $t_source_bug_id, (int)$t_row['relation_type'],
			$t_will_end ? ' WOULD then deactivate (end reached).' : sprintf(
				' Next run %s.', $t_claimed_next > 0 ? date( 'Y-m-d H:i:s', $t_claimed_next ) : '-' )
		) );
		$t_created++;
		continue;
	}

	# ---- Atomically claim this occurrence ---------------------------------
	# Advancing next_run_at conditionally on its current value guarantees that a
	# second, concurrent runner cannot process the same occurrence.
	db_param_push();
	db_query(
		'UPDATE ' . $t_table . ' SET next_run_at = ' . db_param()
		. ', updated_at = ' . db_param()
		. ' WHERE id = ' . db_param()
		. ' AND active = ' . db_param()
		. ' AND next_run_at = ' . db_param(),
		array( $t_claimed_next, $t_now, $t_id, 1, $t_old_next )
	);
	if( db_affected_rows() !== 1 ) {
		lif_cron_log( sprintf(
			'  Schedule #%d: claimed by another run – skipping.', $t_id ) );
		$t_skipped++;
		continue;
	}

	# ---- Build the issue from the stored snapshot -------------------------
	$t_std = json_decode( $t_row['standard_fields_snapshot'], true );
	if( !is_array( $t_std ) ) {
		$t_std = array();
	}
	$t_cf_snapshot = json_decode( $t_row['custom_fields_snapshot'], true );
	if( !is_array( $t_cf_snapshot ) ) {
		$t_cf_snapshot = array();
	}

	$t_issue = array(
		'target_project_id'      => $t_target_pid,
		'summary'                => $t_row['summary_template'],
		'description'            => $t_row['description_template'],
		'steps_to_reproduce'     => $t_row['steps_to_reproduce_template'],
		'additional_information' => $t_row['additional_information_template'],
	);

	if( (int)$t_row['copy_standard_fields'] === 1 ) {
		$t_issue['category_id']     = linked_issue_factory_resolve_category( $t_std, $t_target_pid );
		$t_issue['priority']        = isset( $t_std['priority'] ) ? (int)$t_std['priority'] : 0;
		$t_issue['severity']        = isset( $t_std['severity'] ) ? (int)$t_std['severity'] : 0;
		$t_issue['reproducibility'] = isset( $t_std['reproducibility'] ) ? (int)$t_std['reproducibility'] : 0;
		$t_issue['view_state']      = isset( $t_std['view_state'] ) ? (int)$t_std['view_state'] : 0;

		# Handler only when still valid in the target project.
		$t_handler = isset( $t_std['handler_id'] ) ? (int)$t_std['handler_id'] : 0;
		if( $t_handler > 0
			&& user_exists( $t_handler )
			&& access_has_project_level( config_get( 'handle_bug_threshold' ), $t_target_pid, $t_handler ) ) {
			$t_issue['handler_id'] = $t_handler;
		}
	}

	if( (int)$t_row['copy_custom_fields'] === 1 && !empty( $t_cf_snapshot ) ) {
		$t_allow_name = ( $t_row['custom_field_strategy'] !== 'linked_only' );
		$t_issue['custom_fields'] = linked_issue_factory_map_custom_fields(
			$t_cf_snapshot, $t_target_pid, /* user */ null, $t_allow_name );
	}

	# ---- Decide which ticket the new one is linked to ---------------------
	$t_link_target = (string)linked_issue_factory_config( 'link_target', 'source' );
	$t_master_id = $t_source_bug_id;
	if( $t_link_target === 'last' && (int)$t_row['last_created_bug_id'] > 0 ) {
		$t_master_id = (int)$t_row['last_created_bug_id'];
	}
	# Guard against a deleted master; create without a relationship then.
	if( $t_master_id <= 0 || !bug_exists( $t_master_id ) ) {
		if( $t_master_id > 0 ) {
			lif_cron_log( sprintf(
				'  Schedule #%d: master issue #%d no longer exists – creating without relationship.',
				$t_id, $t_master_id ) );
		}
		$t_master_id = 0;
	}

	# ---- Create the ticket ------------------------------------------------
	try {
		$t_new_bug_id = linked_issue_factory_create_linked_issue(
			$t_issue, $t_master_id, (int)$t_row['relation_type'] );
	} catch( ClientException $e ) {
		# next_run_at is already advanced, so this occurrence is skipped rather
		# than retried forever. Log the reason for the administrator.
		lif_cron_log( sprintf(
			'  Schedule #%d: ERROR creating ticket – %s (occurrence skipped).',
			$t_id, $e->getMessage() ) );
		$t_skipped++;
		continue;
	}

	# ---- Cross-reference notes -------------------------------------------
	linked_issue_factory_add_link_notes( $t_master_id, $t_new_bug_id, /* from_cron */ true );

	# ---- Record the creation & deactivate when finished -------------------
	db_param_push();
	if( $t_will_end ) {
		db_query(
			'UPDATE ' . $t_table . ' SET occurrences_created = occurrences_created + 1'
			. ', last_created_bug_id = ' . db_param()
			. ', next_run_at = ' . db_param()
			. ', active = ' . db_param()
			. ', updated_at = ' . db_param()
			. ' WHERE id = ' . db_param(),
			array( $t_new_bug_id, 0, 0, $t_now, $t_id )
		);
		$t_deactivated++;
		lif_cron_log( sprintf(
			'  Schedule #%d: created ticket #%d and DEACTIVATED (end reached).',
			$t_id, $t_new_bug_id ) );
	} else {
		db_query(
			'UPDATE ' . $t_table . ' SET occurrences_created = occurrences_created + 1'
			. ', last_created_bug_id = ' . db_param()
			. ', updated_at = ' . db_param()
			. ' WHERE id = ' . db_param(),
			array( $t_new_bug_id, $t_now, $t_id )
		);
		lif_cron_log( sprintf(
			'  Schedule #%d: created ticket #%d. Next run %s.',
			$t_id, $t_new_bug_id, date( 'Y-m-d H:i:s', $t_claimed_next ) ) );
	}
	$t_created++;
}

lif_cron_log( sprintf(
	'Done. %d schedule(s) examined, %d ticket(s) %s, %d skipped, %d deactivated.',
	$t_examined,
	$t_created,
	$g_dry_run ? 'would be created' : 'created',
	$t_skipped,
	$t_deactivated
) );

exit( 0 );
