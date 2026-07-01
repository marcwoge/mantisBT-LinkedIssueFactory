<?php
/**
 * LinkedIssueFactory – shared helper library.
 *
 * These functions are the single source of truth for the plugin's business
 * logic. They are required both by the web pages (through the main plugin
 * class {@see LinkedIssueFactoryPlugin}) and by the standalone CLI cron runner
 * ({@see scripts/linked_issue_factory_cron.php}). Keeping the logic in one file
 * avoids the copy/paste duplication that a MantisBT plugin usually needs
 * between web and CLI context.
 *
 * The file only ever uses MantisBT core APIs; direct SQL is limited to the
 * plugin's own schedule table. It expects MantisBT core (`core.php`) to be
 * loaded already – every consumer requires it before pulling in this file.
 *
 * @package   LinkedIssueFactory
 * @author    Marc-Philipp Woge
 * @license   MIT
 * @link      https://github.com/marcwoge/reveille (architectural blueprint)
 */

# Refuse to run outside of the MantisBT context. When required from a plugin
# page or the cron runner, MANTIS_VERSION is always defined.
if( !defined( 'MANTIS_VERSION' ) ) {
	die( 'LinkedIssueFactory helper library cannot be called directly.' );
}

require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'bug_api.php' );
require_api( 'bugnote_api.php' );
require_api( 'category_api.php' );
require_api( 'custom_field_api.php' );
require_api( 'relationship_api.php' );

# The command layer is not auto-loaded; pull it in so the payload wrapper works
# in both web and CLI context.
require_once( config_get_global( 'core_path' ) . 'commands' . DIRECTORY_SEPARATOR . 'IssueAddCommand.php' );

/* -------------------------------------------------------------------------- *
 *  Constants / configuration helpers
 * -------------------------------------------------------------------------- */

if( !function_exists( 'linked_issue_factory_basename' ) ) {
	/**
	 * The plugin basename used for plugin_config_get() lookups. Hard-coded so
	 * that the CLI runner (where plugin_get_current() is unavailable) resolves
	 * configuration through the plain config_get( 'plugin_<Basename>_<key>' )
	 * convention.
	 *
	 * @return string
	 */
	function linked_issue_factory_basename() {
		return 'LinkedIssueFactory';
	}
}

if( !function_exists( 'linked_issue_factory_config' ) ) {
	/**
	 * Reads a plugin configuration value. Works both when the plugin layer is
	 * active (web request) and when it is not (CLI cron), because MantisBT
	 * persists plugin configuration in the ordinary config table under the key
	 * "plugin_<Basename>_<option>".
	 *
	 * @param string $p_option  Option name.
	 * @param mixed  $p_default Default value if the option is unset.
	 * @return mixed
	 */
	function linked_issue_factory_config( $p_option, $p_default = null ) {
		return config_get(
			'plugin_' . linked_issue_factory_basename() . '_' . $p_option,
			$p_default
		);
	}
}

if( !function_exists( 'linked_issue_factory_schedule_table' ) ) {
	/**
	 * Fully qualified name of the schedule table, honouring the configured DB
	 * table prefix. With the MantisBT default prefix ("mantis") this resolves to
	 * "mantis_linked_issue_factory_schedule".
	 *
	 * The exact same expression is used by the plugin's schema() migration when
	 * the table is created, so the physical name and the query name are always
	 * in sync – independent of any db_table_suffix / plugin-prefix settings.
	 * This mirrors the naming approach used by the Reveille reference plugin and
	 * works identically in web and CLI context (plugin layer not required).
	 *
	 * @return string
	 */
	function linked_issue_factory_schedule_table() {
		return config_get_global( 'db_table_prefix' ) . '_linked_issue_factory_schedule';
	}
}

/* -------------------------------------------------------------------------- *
 *  Relationship types
 * -------------------------------------------------------------------------- */

if( !function_exists( 'linked_issue_factory_relationship_types' ) ) {
	/**
	 * The relationship types the plugin offers, mapped to a localized label
	 * describing what the NEW ticket is relative to the source ticket.
	 *
	 * The plugin creates the relationship with relationship_add( new, source,
	 * type ) – identical to how MantisBT's own "create clone" flow does it – so
	 * the source-side description is the correct label for the new ticket.
	 *
	 * Only the relationship types that MantisBT core actually defines are
	 * offered, so the plugin never feeds relationship_add() an invalid type.
	 * MantisBT models parent/child as the BUG_DEPENDANT / BUG_BLOCKS pair.
	 *
	 * @return array<int,string> type id => label
	 */
	function linked_issue_factory_relationship_types() {
		$t_types = array(
			BUG_RELATED,       # new is "related to" source
			BUG_DEPENDANT,     # new is "parent of" source
			BUG_BLOCKS,        # new is "child of" source
			BUG_DUPLICATE,     # new is "duplicate of" source
			BUG_HAS_DUPLICATE, # new "has duplicate" source
		);

		$t_result = array();
		foreach( $t_types as $t_type ) {
			# relationship_get_description_src_side() returns a localized string
			# straight from MantisBT, so the labels always match the core UI.
			$t_result[$t_type] = relationship_get_description_src_side( $t_type );
		}
		return $t_result;
	}
}

if( !function_exists( 'linked_issue_factory_relationship_is_valid' ) ) {
	/**
	 * Whether a relationship type is one the plugin is allowed to create.
	 *
	 * @param int $p_type
	 * @return bool
	 */
	function linked_issue_factory_relationship_is_valid( $p_type ) {
		return array_key_exists( (int)$p_type, linked_issue_factory_relationship_types() );
	}
}

if( !function_exists( 'linked_issue_factory_default_relationship_type' ) ) {
	/**
	 * The configured default relationship type, falling back to BUG_RELATED.
	 * The default is intentionally "related to" – a ticket derived from another
	 * is not necessarily a MantisBT duplicate.
	 *
	 * @return int
	 */
	function linked_issue_factory_default_relationship_type() {
		$t_type = (int)linked_issue_factory_config( 'default_relationship_type', BUG_RELATED );
		return linked_issue_factory_relationship_is_valid( $t_type ) ? $t_type : BUG_RELATED;
	}
}

/* -------------------------------------------------------------------------- *
 *  Recurrence helpers
 * -------------------------------------------------------------------------- */

if( !function_exists( 'linked_issue_factory_recurrence_types' ) ) {
	/**
	 * Allowed recurrence types.
	 * @return string[]
	 */
	function linked_issue_factory_recurrence_types() {
		return array( 'once', 'daily', 'weekly', 'monthly', 'yearly', 'interval' );
	}
}

if( !function_exists( 'linked_issue_factory_recurrence_units' ) ) {
	/**
	 * Allowed units for the "interval" recurrence type.
	 * @return string[]
	 */
	function linked_issue_factory_recurrence_units() {
		return array( 'days', 'weeks', 'months', 'years' );
	}
}

if( !function_exists( 'linked_issue_factory_normalize_recurrence' ) ) {
	/**
	 * Reduces a (type, interval, unit) triple to an effective (interval, unit)
	 * pair understood by strtotime(). "once" returns null (no recurrence).
	 *
	 * @param string $p_type     One of linked_issue_factory_recurrence_types().
	 * @param int    $p_interval Only used for the "interval" type.
	 * @param string $p_unit     Only used for the "interval" type.
	 * @return array{interval:int,unit:string}|null
	 */
	function linked_issue_factory_normalize_recurrence( $p_type, $p_interval, $p_unit ) {
		switch( $p_type ) {
			case 'once':
				return null;
			case 'daily':
				return array( 'interval' => 1, 'unit' => 'days' );
			case 'weekly':
				return array( 'interval' => 1, 'unit' => 'weeks' );
			case 'monthly':
				return array( 'interval' => 1, 'unit' => 'months' );
			case 'yearly':
				return array( 'interval' => 1, 'unit' => 'years' );
			case 'interval':
			default:
				$t_interval = (int)$p_interval;
				if( $t_interval < 1 ) {
					$t_interval = 1;
				}
				$t_unit = in_array( $p_unit, linked_issue_factory_recurrence_units(), true )
					? $p_unit : 'days';
				return array( 'interval' => $t_interval, 'unit' => $t_unit );
		}
	}
}

if( !function_exists( 'linked_issue_factory_advance_timestamp' ) ) {
	/**
	 * Advances a timestamp by one recurrence step. Uses strtotime() so that
	 * calendar-aware units (months, years) behave sensibly.
	 *
	 * @param int    $p_from     Base Unix timestamp.
	 * @param int    $p_interval
	 * @param string $p_unit     days|weeks|months|years
	 * @return int New Unix timestamp.
	 */
	function linked_issue_factory_advance_timestamp( $p_from, $p_interval, $p_unit ) {
		$t_unit = in_array( $p_unit, linked_issue_factory_recurrence_units(), true ) ? $p_unit : 'days';
		$t_interval = (int)$p_interval;
		if( $t_interval < 1 ) {
			$t_interval = 1;
		}
		$t_next = strtotime( '+' . $t_interval . ' ' . $t_unit, (int)$p_from );
		return $t_next === false ? (int)$p_from : $t_next;
	}
}

if( !function_exists( 'linked_issue_factory_compute_next_run' ) ) {
	/**
	 * Computes the next run timestamp strictly greater than a lower bound,
	 * advancing repeatedly from the previous scheduled time to avoid drift while
	 * skipping any missed occurrences in a single catch-up.
	 *
	 * @param int    $p_previous_run Previous scheduled timestamp.
	 * @param string $p_type
	 * @param int    $p_interval
	 * @param string $p_unit
	 * @param int    $p_not_before   Result must be > this (usually "now").
	 * @return int|null New timestamp, or null when the schedule does not recur.
	 */
	function linked_issue_factory_compute_next_run( $p_previous_run, $p_type, $p_interval, $p_unit, $p_not_before ) {
		$t_step = linked_issue_factory_normalize_recurrence( $p_type, $p_interval, $p_unit );
		if( $t_step === null ) {
			return null; # "once" – no follow-up run.
		}

		$t_next = linked_issue_factory_advance_timestamp(
			$p_previous_run, $t_step['interval'], $t_step['unit'] );

		# Skip past any occurrences that were missed (e.g. cron was down), but
		# guard against an infinite loop with a generous cap.
		$t_guard = 0;
		while( $t_next <= (int)$p_not_before && $t_guard < 100000 ) {
			$t_next = linked_issue_factory_advance_timestamp(
				$t_next, $t_step['interval'], $t_step['unit'] );
			$t_guard++;
		}
		return $t_next;
	}
}

/* -------------------------------------------------------------------------- *
 *  Custom-field copy logic
 * -------------------------------------------------------------------------- */

if( !function_exists( 'linked_issue_factory_types_compatible' ) ) {
	/**
	 * Whether two custom-field types are compatible enough to copy a value
	 * across. Identical types are always compatible; the text-ish string family
	 * is treated as mutually compatible.
	 *
	 * @param int $p_type_a
	 * @param int $p_type_b
	 * @return bool
	 */
	function linked_issue_factory_types_compatible( $p_type_a, $p_type_b ) {
		$p_type_a = (int)$p_type_a;
		$p_type_b = (int)$p_type_b;
		if( $p_type_a === $p_type_b ) {
			return true;
		}
		$t_textish = array(
			CUSTOM_FIELD_TYPE_STRING,
			CUSTOM_FIELD_TYPE_TEXTAREA,
			CUSTOM_FIELD_TYPE_EMAIL,
		);
		return in_array( $p_type_a, $t_textish, true )
			&& in_array( $p_type_b, $t_textish, true );
	}
}

if( !function_exists( 'linked_issue_factory_collect_source_custom_fields' ) ) {
	/**
	 * Reads every custom-field value the source bug carries that the acting user
	 * is allowed to read, keyed by field id. Values that cannot be read are
	 * simply omitted – the plugin never leaks fields the user may not see.
	 *
	 * @param int      $p_source_bug_id
	 * @param int|null $p_user_id Reader (defaults to current user).
	 * @return array<int,array{id:int,name:string,type:int,value:string}>
	 */
	function linked_issue_factory_collect_source_custom_fields( $p_source_bug_id, $p_user_id = null ) {
		$t_source_project = bug_get_field( $p_source_bug_id, 'project_id' );
		$t_linked = custom_field_get_linked_ids( $t_source_project );

		$t_out = array();
		foreach( $t_linked as $t_field_id ) {
			if( !custom_field_has_read_access( $t_field_id, $p_source_bug_id, $p_user_id ) ) {
				continue;
			}
			$t_value = custom_field_get_value( $t_field_id, $p_source_bug_id );
			if( $t_value === null || $t_value === false ) {
				continue;
			}
			$t_def = custom_field_get_definition( $t_field_id );
			$t_out[(int)$t_field_id] = array(
				'id'    => (int)$t_field_id,
				'name'  => $t_def['name'],
				'type'  => (int)$t_def['type'],
				'value' => (string)$t_value,
			);
		}
		return $t_out;
	}
}

if( !function_exists( 'linked_issue_factory_map_custom_fields' ) ) {
	/**
	 * Maps a set of source custom-field values onto the target project.
	 *
	 * Copy rules (per the plugin brief):
	 *   1. Same field id linked to the target project    -> copy.
	 *   2. Field with same name and a compatible type     -> copy.
	 *   3. Field not present / not writable in target      -> skip.
	 *   4. Value invalid for the target field              -> skip.
	 *
	 * The result is the "custom_fields" payload IssueAddCommand expects, i.e. a
	 * list of array( 'field' => array( 'id' => <id> ), 'value' => <value> ).
	 * Only fields the acting user may write to the target project are included.
	 *
	 * @param array    $p_source_fields  Output of collect_source_custom_fields().
	 * @param int      $p_target_project_id
	 * @param int|null $p_user_id        Writer (defaults to current user).
	 * @param bool     $p_allow_name_match When false, only rule 1 (same field id)
	 *                                     applies – used by the "linked only"
	 *                                     schedule strategy.
	 * @return array List of payload entries.
	 */
	function linked_issue_factory_map_custom_fields( array $p_source_fields, $p_target_project_id, $p_user_id = null, $p_allow_name_match = true ) {
		$t_target_ids = custom_field_get_linked_ids( $p_target_project_id );

		# Index target fields by id and by lower-cased name for quick matching.
		$t_target_by_id   = array();
		$t_target_by_name = array();
		foreach( $t_target_ids as $t_id ) {
			$t_def = custom_field_get_definition( $t_id );
			$t_target_by_id[(int)$t_id]                     = $t_def;
			$t_target_by_name[mb_strtolower( $t_def['name'] )] = $t_def;
		}

		$t_payload = array();
		$t_used_target_ids = array();

		foreach( $p_source_fields as $t_src ) {
			$t_target_def = null;

			# Rule 1: same field id linked to the target project.
			if( isset( $t_target_by_id[$t_src['id']] ) ) {
				$t_target_def = $t_target_by_id[$t_src['id']];
			} else if( $p_allow_name_match ) {
				# Rule 2: same name + compatible type.
				$t_key = mb_strtolower( $t_src['name'] );
				if( isset( $t_target_by_name[$t_key] )
					&& linked_issue_factory_types_compatible( $t_src['type'], $t_target_by_name[$t_key]['type'] ) ) {
					$t_target_def = $t_target_by_name[$t_key];
				}
			}

			if( $t_target_def === null ) {
				continue; # Rule 3: not present in target.
			}

			$t_target_id = (int)$t_target_def['id'];
			if( isset( $t_used_target_ids[$t_target_id] ) ) {
				continue; # already filled from an earlier source field
			}

			# Must be writable by the acting user in the target project.
			if( !custom_field_has_write_access_to_project( $t_target_id, $p_target_project_id, $p_user_id ) ) {
				continue;
			}

			# Rule 4: value must be valid for the target field.
			if( !custom_field_validate( $t_target_id, $t_src['value'] ) ) {
				continue;
			}

			$t_payload[] = array(
				'field' => array( 'id' => $t_target_id ),
				'value' => $t_src['value'],
			);
			$t_used_target_ids[$t_target_id] = true;
		}

		return $t_payload;
	}
}

/* -------------------------------------------------------------------------- *
 *  Issue creation
 * -------------------------------------------------------------------------- */

if( !function_exists( 'linked_issue_factory_create_linked_issue' ) ) {
	/**
	 * Creates a new issue in the target project, linked back to the source.
	 *
	 * All validation and access control is delegated to MantisBT's own
	 * IssueAddCommand: it verifies the acting user may report in the target
	 * project, validates custom fields (including required ones), creates the
	 * relationship and writes the "created from / cloned to" history entries.
	 *
	 * @param array $p_issue  Issue field map. Recognized keys:
	 *   target_project_id (int, required), summary (string, required),
	 *   description (string, required), steps_to_reproduce, additional_information,
	 *   category_id, priority, severity, reproducibility, handler_id (0 = none),
	 *   view_state, due_date (Unix ts or 0), custom_fields (payload list).
	 * @param int   $p_source_bug_id Master issue to link to.
	 * @param int   $p_relationship_type One of the offered relationship types.
	 * @return int The new bug id.
	 * @throws \Mantis\Exceptions\ClientException On validation / access failure.
	 */
	function linked_issue_factory_create_linked_issue( array $p_issue, $p_source_bug_id, $p_relationship_type ) {
		$t_project_id = (int)$p_issue['target_project_id'];

		$t_payload_issue = array(
			'project'     => array( 'id' => $t_project_id ),
			'summary'     => (string)$p_issue['summary'],
			'description' => (string)$p_issue['description'],
		);

		if( isset( $p_issue['steps_to_reproduce'] ) && !is_blank( $p_issue['steps_to_reproduce'] ) ) {
			$t_payload_issue['steps_to_reproduce'] = (string)$p_issue['steps_to_reproduce'];
		}
		if( isset( $p_issue['additional_information'] ) && !is_blank( $p_issue['additional_information'] ) ) {
			$t_payload_issue['additional_information'] = (string)$p_issue['additional_information'];
		}
		if( !empty( $p_issue['category_id'] ) ) {
			$t_payload_issue['category'] = array( 'id' => (int)$p_issue['category_id'] );
		}
		if( !empty( $p_issue['priority'] ) ) {
			$t_payload_issue['priority'] = array( 'id' => (int)$p_issue['priority'] );
		}
		if( !empty( $p_issue['severity'] ) ) {
			$t_payload_issue['severity'] = array( 'id' => (int)$p_issue['severity'] );
		}
		if( !empty( $p_issue['reproducibility'] ) ) {
			$t_payload_issue['reproducibility'] = array( 'id' => (int)$p_issue['reproducibility'] );
		}
		if( !empty( $p_issue['handler_id'] ) ) {
			$t_payload_issue['handler'] = array( 'id' => (int)$p_issue['handler_id'] );
		}
		if( !empty( $p_issue['view_state'] ) ) {
			$t_payload_issue['view_state'] = array( 'id' => (int)$p_issue['view_state'] );
		}
		if( !empty( $p_issue['due_date'] ) ) {
			# IssueAddCommand runs the value through strtotime(); hand it an
			# ISO string so it is interpreted unambiguously.
			$t_payload_issue['due_date'] = date( 'Y-m-d H:i:s', (int)$p_issue['due_date'] );
		}
		if( !empty( $p_issue['custom_fields'] ) && is_array( $p_issue['custom_fields'] ) ) {
			$t_payload_issue['custom_fields'] = $p_issue['custom_fields'];
		}

		$t_data = array(
			'payload' => array( 'issue' => $t_payload_issue ),
		);

		if( $p_source_bug_id > 0 ) {
			$t_data['options'] = array(
				'clone_info' => array(
					'master_issue_id'   => (int)$p_source_bug_id,
					'relationship_type' => (int)$p_relationship_type,
					'copy_notes'        => false,
					'copy_files'        => false,
				),
			);
		}

		$t_command = new IssueAddCommand( $t_data );
		$t_result = $t_command->execute();

		return (int)$t_result['issue_id'];
	}
}

if( !function_exists( 'linked_issue_factory_add_link_notes' ) ) {
	/**
	 * Adds the "an issue was derived from this one" / "this issue was derived
	 * from" cross-reference notes, honouring the plugin configuration flags.
	 *
	 * @param int  $p_source_bug_id
	 * @param int  $p_new_bug_id
	 * @param bool $p_from_cron When true, note wording mentions the schedule.
	 * @return void
	 */
	function linked_issue_factory_add_link_notes( $p_source_bug_id, $p_new_bug_id, $p_from_cron = false ) {
		if( !linked_issue_factory_config( 'add_internal_notes', true ) ) {
			return;
		}
		$t_private = (bool)linked_issue_factory_config( 'notes_private', true );

		# The plugin language is loaded on web requests but NOT in the CLI cron,
		# so a sensible English fallback is supplied for each wording.
		$t_key_source = $p_from_cron ? 'note_source_recurring' : 'note_source_once';
		$t_default_source = $p_from_cron
			? '[LinkedIssueFactory] A recurring linked issue #%d was created from this issue.'
			: '[LinkedIssueFactory] A linked issue #%d was created from this issue.';
		$t_note_source = sprintf(
			lang_get_defaulted(
				'plugin_' . linked_issue_factory_basename() . '_' . $t_key_source,
				$t_default_source
			),
			$p_new_bug_id
		);
		$t_note_new = sprintf(
			lang_get_defaulted(
				'plugin_' . linked_issue_factory_basename() . '_note_new_issue',
				'[LinkedIssueFactory] This issue was created from source issue #%d.'
			),
			$p_source_bug_id
		);

		if( $p_source_bug_id > 0 && bug_exists( $p_source_bug_id ) ) {
			bugnote_add( $p_source_bug_id, $t_note_source, '0:00', $t_private, BUGNOTE, '', null, false );
		}
		bugnote_add( $p_new_bug_id, $t_note_new, '0:00', $t_private, BUGNOTE, '', null, false );
	}
}

/* -------------------------------------------------------------------------- *
 *  Snapshot helpers (used when persisting a recurring schedule)
 * -------------------------------------------------------------------------- */

if( !function_exists( 'linked_issue_factory_snapshot_standard_fields' ) ) {
	/**
	 * Captures the standard field values of a bug into a plain array suitable
	 * for JSON storage in the schedule template. The cron runner replays this
	 * snapshot so that recurring creation does not depend on the source ticket
	 * remaining unchanged.
	 *
	 * @param int $p_bug_id
	 * @return array
	 */
	function linked_issue_factory_snapshot_standard_fields( $p_bug_id ) {
		$t_bug = bug_get( $p_bug_id, true );
		# The category NAME is captured alongside the id so the cron can re-resolve
		# it in a different target project (ids are project specific, names often
		# match). Priority / severity / reproducibility / view_state are global
		# enums and safe to copy verbatim.
		$t_category_name = '';
		if( $t_bug->category_id > 0 && category_exists( $t_bug->category_id ) ) {
			$t_category_name = category_get_field( $t_bug->category_id, 'name' );
		}
		return array(
			'category_id'     => (int)$t_bug->category_id,
			'category_name'   => $t_category_name,
			'priority'        => (int)$t_bug->priority,
			'severity'        => (int)$t_bug->severity,
			'reproducibility' => (int)$t_bug->reproducibility,
			'handler_id'      => (int)$t_bug->handler_id,
			'view_state'      => (int)$t_bug->view_state,
		);
	}
}

if( !function_exists( 'linked_issue_factory_resolve_category' ) ) {
	/**
	 * Resolves a standard-field snapshot's category into a category id that is
	 * valid for the target project:
	 *   1. snapshot category_id if it exists in the target project, else
	 *   2. a target category with the same name, else
	 *   3. 0 (caller should let IssueAddCommand pick the project default).
	 *
	 * @param array $p_snapshot         Output of snapshot_standard_fields().
	 * @param int   $p_target_project_id
	 * @return int Category id, or 0 when no compatible category was found.
	 */
	function linked_issue_factory_resolve_category( array $p_snapshot, $p_target_project_id ) {
		$t_id = isset( $p_snapshot['category_id'] ) ? (int)$p_snapshot['category_id'] : 0;
		if( $t_id > 0 && category_exists_in_project( $t_id, $p_target_project_id ) ) {
			return $t_id;
		}
		$t_name = isset( $p_snapshot['category_name'] ) ? (string)$p_snapshot['category_name'] : '';
		if( $t_name !== '' ) {
			foreach( category_get_all_rows( $p_target_project_id ) as $t_cat ) {
				if( $t_cat['name'] === $t_name ) {
					return (int)$t_cat['id'];
				}
			}
		}
		return 0;
	}
}

if( !function_exists( 'linked_issue_factory_snapshot_custom_fields' ) ) {
	/**
	 * Captures the source bug's custom-field values (that the user may read)
	 * into a JSON-serialisable list for the schedule template.
	 *
	 * @param int      $p_bug_id
	 * @param int|null $p_user_id
	 * @return array
	 */
	function linked_issue_factory_snapshot_custom_fields( $p_bug_id, $p_user_id = null ) {
		return array_values(
			linked_issue_factory_collect_source_custom_fields( $p_bug_id, $p_user_id )
		);
	}
}
