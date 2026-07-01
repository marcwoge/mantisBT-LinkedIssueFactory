<?php
/**
 * LinkedIssueFactory – create a linked new ticket from an existing one,
 * either once or on a recurring schedule.
 *
 * MantisBT plugin main class. Holds the manifest, the configuration defaults,
 * the schema migration and the event hooks (issue-view button + management
 * menu). The reusable business logic lives in
 * core/linked_issue_factory_api.php, which is pulled in here so every plugin
 * page can use it; the CLI cron runner includes the same library directly.
 *
 * MantisBT loads exactly one class file per plugin and expects it to be named
 * after the plugin directory ("LinkedIssueFactory.php") and to contain the
 * class "<PluginName>Plugin" ("LinkedIssueFactoryPlugin").
 *
 * @package   LinkedIssueFactory
 * @author    Marc-Philipp Woge
 * @license   MIT
 * @link      https://github.com/marcwoge/reveille (architectural blueprint)
 */

# Refuse to run outside of the MantisBT context.
if( !defined( 'MANTIS_VERSION' ) ) {
	die( 'LinkedIssueFactory is a MantisBT plugin and cannot be called directly.' );
}

# Shared helper library – makes the file-scope helpers available to all pages.
require_once( __DIR__ . DIRECTORY_SEPARATOR . 'core'
	. DIRECTORY_SEPARATOR . 'linked_issue_factory_api.php' );

require_once( config_get_global( 'class_path' ) . 'MantisPlugin.class.php' );

/**
 * @noinspection PhpUnused – instantiated by the MantisBT plugin loader.
 */
class LinkedIssueFactoryPlugin extends MantisPlugin {

	/**
	 * Plugin manifest.
	 * @return void
	 */
	function register() {
		# Literal strings: the plugin language is not loaded yet at registration.
		$this->name        = 'Linked Issue Factory';
		$this->description  = 'Create a linked new ticket from an existing one – once or on a recurring schedule.';
		$this->page         = 'config';

		$this->version  = '1.0.1';
		$this->requires = array(
			'MantisCore' => '2.0.0',
		);

		$this->author  = 'Marc-Philipp Woge';
		$this->contact = 'marc.woge@googlemail.com';
		$this->url     = 'https://github.com/marcwoge/mantisBT-LinkedIssueFactory';
	}

	/**
	 * Default configuration values. Persisted in the ordinary MantisBT config
	 * table under "plugin_LinkedIssueFactory_<key>", which is why the CLI runner
	 * can read them without loading the plugin layer.
	 * @return array
	 */
	function config() {
		return array(
			# Default relationship type for new linked tickets (BUG_RELATED = 1).
			# Deliberately "related", not "duplicate".
			'default_relationship_type' => BUG_RELATED,
			# Copy custom fields by default when creating a linked ticket.
			'copy_custom_fields_default' => 1,
			# Optional default target project (0 = none / same as source).
			'default_target_project' => 0,
			# Master switch for the recurring-ticket feature.
			'enable_recurring' => 1,
			# Technical user the cron logs in as (empty = use env var / administrator).
			'cron_user' => '',
			# Default recurrence for new schedules.
			'default_recurrence_type' => 'monthly',
			# Whether the cron writes internal cross-reference notes.
			'add_internal_notes' => 1,
			# Link the recurring ticket to the ORIGINAL source ('source') or to
			# the most recently created ticket ('last').
			'link_target' => 'source',
			# Whether the cross-reference notes are created as private notes.
			'notes_private' => 1,
		);
	}

	/**
	 * Event hooks.
	 * @return array
	 */
	function hooks() {
		return array(
			'EVENT_MENU_ISSUE'  => 'menu_issue',
			'EVENT_MENU_MANAGE' => 'menu_manage',
		);
	}

	/**
	 * Adds the "Create linked ticket" button to the issue-view menu.
	 *
	 * The user only sees the button when they are allowed to view the issue
	 * (they already are, or the page would not render) – the target-project
	 * permission check happens on the form / action pages.
	 *
	 * @param string  $p_event   Event name (unused).
	 * @param integer $p_bug_id  Issue id the menu is rendered for.
	 * @return array Associative array( label => href ). MantisBT wraps the
	 *               handler result per plugin/callback itself, so a single
	 *               label => url map is the correct return shape (a non-numeric
	 *               key makes the core renderer emit a labelled button).
	 */
	function menu_issue( $p_event, $p_bug_id ) {
		$t_bug_id = (int)$p_bug_id;
		return array(
			plugin_lang_get( 'menu_create_related' ) =>
				plugin_page( 'create_related' ) . '&bug_id=' . $t_bug_id,
		);
	}

	/**
	 * Adds the plugin entries to the "Manage" menu.
	 * @return array
	 */
	function menu_manage() {
		$t_items = array(
			'<a href="' . plugin_page( 'config' ) . '">'
				. plugin_lang_get( 'menu_config' ) . '</a>',
		);

		if( linked_issue_factory_config( 'enable_recurring', true ) ) {
			$t_items[] = '<a href="' . plugin_page( 'schedule_list' ) . '">'
				. plugin_lang_get( 'menu_schedules' ) . '</a>';
			$t_items[] = '<a href="' . plugin_page( 'dashboard' ) . '">'
				. plugin_lang_get( 'menu_dashboard' ) . '</a>';
		}

		return $t_items;
	}

	/**
	 * Schema migration. Creates the recurring-schedule table.
	 *
	 * The table name honours the configured DB prefix (default
	 * "mantis_linked_issue_factory_schedule"). ENUM-like columns are stored as
	 * short VARCHARs to stay portable across the databases MantisBT supports;
	 * the allowed values are enforced at the application level. All date fields
	 * are stored as integer Unix timestamps, matching how MantisBT stores
	 * bug.due_date after its own date migration.
	 *
	 * @return array
	 */
	function schema() {
		return array(
			# 0 – schedule / recurring-template table.
			array( 'CreateTableSQL', array( linked_issue_factory_schedule_table(), "
				id                              I       UNSIGNED NOTNULL PRIMARY AUTOINCREMENT,
				source_bug_id                   I       UNSIGNED NOTNULL DEFAULT '0',
				target_project_id               I       UNSIGNED NOTNULL DEFAULT '0',
				relation_type                   I2      NOTNULL DEFAULT '1',
				summary_template                C(128)  NOTNULL DEFAULT \" '' \",
				description_template            XL      NOTNULL,
				steps_to_reproduce_template     XL      NOTNULL,
				additional_information_template XL      NOTNULL,
				standard_fields_snapshot        XL      NOTNULL,
				custom_fields_snapshot          XL      NOTNULL,
				copy_standard_fields            L       NOTNULL DEFAULT \" '1' \",
				copy_custom_fields              L       NOTNULL DEFAULT \" '1' \",
				custom_field_strategy           C(20)   NOTNULL DEFAULT \" 'linked_or_name' \",
				recurrence_type                 C(20)   NOTNULL DEFAULT \" 'monthly' \",
				recurrence_interval             I       NOTNULL DEFAULT '1',
				recurrence_unit                 C(10)   NOTNULL DEFAULT \" 'days' \",
				start_at                        I       NOTNULL DEFAULT '0',
				next_run_at                     I       NOTNULL DEFAULT '0',
				end_at                          I       NOTNULL DEFAULT '0',
				max_occurrences                 I       NOTNULL DEFAULT '0',
				occurrences_created             I       NOTNULL DEFAULT '0',
				active                          L       NOTNULL DEFAULT \" '1' \",
				last_created_bug_id             I       UNSIGNED NOTNULL DEFAULT '0',
				created_by                      I       UNSIGNED NOTNULL DEFAULT '0',
				created_at                      I       NOTNULL DEFAULT '0',
				updated_at                      I       NOTNULL DEFAULT '0'
				" ) ),
			# 1 – index for the cron's "due schedules" lookup.
			array( 'CreateIndexSQL', array( 'idx_lif_next_run',
				linked_issue_factory_schedule_table(), 'active, next_run_at' ) ),
		);
	}
}
