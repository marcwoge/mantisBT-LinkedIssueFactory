# LinkedIssueFactory

A MantisBT 2.x plugin that creates a **new, linked ticket** from an existing
one – either **once** or on a **recurring schedule**. The new ticket can be
created in any project the current user is allowed to report in, with its fields
pre-filled from the source ticket.

> 🇩🇪 Eine deutsche Fassung dieser Anleitung finden Sie in
> [README.de.md](README.de.md).

---

## Purpose

From the detail view of any existing ticket you get a **“Create linked ticket”**
button. It opens a form that lets you:

* pick a **target project** (only projects you may report in are offered),
* review the **summary / description / steps / additional information**
  pre-filled from the source ticket,
* choose **category, priority, severity, reproducibility, assignee, view state,
  due date**,
* pick the **relationship** the new ticket has to the source (default:
  *related to*, not *duplicate*),
* copy **custom fields** that are valid in the target project,
* create the ticket **now**, or **plan a recurrence** so the ticket is generated
  automatically at defined intervals.

The plugin uses MantisBT core APIs throughout (`IssueAddCommand`,
`relationship_api`, `custom_field_api`, …). It performs **no core
modifications** and has **no Composer / npm dependencies**.

---

## Repository layout

The installable plugin lives in its own self-contained folder; everything else
is tooling for local testing.

```
LinkedIssueFactory/     ← the plugin – copy THIS folder into <mantis>/plugins/
docker/                 ← local MantisBT sandbox (see docker/README.md)
docker-compose.yml
docs/MANUAL_TESTS.md    ← manual test checklist
```

The release also ships a ready-to-drop archive containing only the
`LinkedIssueFactory/` folder – see the
[latest release](https://github.com/marcwoge/mantisBT-LinkedIssueFactory/releases/latest).

---

## Requirements

* MantisBT **2.x** (developed and tested against 2.28)
* PHP **7.4+**

---

## Installation

1. Copy the plugin into your MantisBT `plugins/` directory so that the main
   class file ends up at:

   ```
   <mantis>/plugins/LinkedIssueFactory/LinkedIssueFactory.php
   ```

2. In MantisBT, open **Manage → Manage Plugins**.
3. Next to **Linked Issue Factory**, click **Install**. The plugin’s schema
   migration creates its database table automatically (see
   [Database](#database)).
4. Configure the plugin under
   **Manage → Manage Plugins → Linked Issue Factory** (or the *Manage* menu
   entries the plugin adds).

No manual SQL is required – the table is created through the plugin’s `schema()`
method, honouring your configured DB table prefix.

---

## Configuration

**Manage → Linked Issue Factory → Configuration**:

| Option | Description |
| --- | --- |
| Default relationship type | Relationship applied to new linked tickets (default *related to*). |
| Copy custom fields by default | Pre-fill custom fields from the source ticket. |
| Default target project | Optional pre-selected target project (else the source project). |
| Enable recurring tickets | Master switch for the schedule feature and its pages. |
| Default recurrence | Default recurrence type offered on new schedules. |
| Technical cron user | Account the cron logs in as (unless overridden by the environment variable). |
| Link recurring ticket to | Link each recurring ticket to the **original source** or the **most recently created** ticket. |
| Add internal notes | Write cross-reference notes into the source and the new ticket. |
| Make internal notes private | Create those notes as private. |

All values are stored via `plugin_config_set()` in the standard MantisBT config
table, which is why the CLI cron can read them.

---

## Using it from a ticket

1. Open any ticket.
2. Click **Create linked ticket** in the ticket’s action bar.
3. Choose the target project (the form reloads to show that project’s
   categories and custom fields).
4. Adjust the pre-filled fields as needed.
5. Click **Create ticket now** – or **Plan recurrence…** to define a schedule.

On creation the plugin:

* creates the new ticket in the target project,
* creates the chosen relationship to the source ticket,
* copies the applicable custom fields,
* writes cross-reference notes (if enabled).

---

## Recurring tickets

A schedule is a stored **template** plus a **recurrence rule**. It is created
from a source ticket (via **Plan recurrence…**) and lives in the plugin’s own
table. The source ticket is never modified by the recurrence; each run produces
a brand-new ticket.

Supported recurrences: **once, daily, weekly, monthly, yearly**, or a free-form
**every *X* days/weeks/months/years**. Each schedule has a start date, an
optional end date, an optional maximum number of occurrences, and an
active/inactive flag.

Manage schedules under **Manage → Linked Issue Factory → Schedules**; see the
next scheduled creations under **… → Dashboard**.

### Field snapshots

When a schedule is saved, the source ticket’s **standard fields** and
**custom-field values** are captured as a JSON snapshot. The cron replays that
snapshot, so recurring creation keeps working even if the source ticket is later
changed or deleted. The category is re-resolved **by name** in the target
project (ids are project-specific).

---

## Cron setup

The recurring tickets are generated by a CLI script:

```
<mantis>/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php
```

Options:

| Option | Effect |
| --- | --- |
| `--dry-run` | Show what would happen; write nothing. |
| `--schedule-id=<id>` | Only process one schedule (handy for testing). |
| `--verbose` | Also log skipped / not-yet-due schedules. |
| `--help`, `-h` | Show usage. |

The script logs in as a technical MantisBT user, resolved in this order:

1. environment variable `LINKED_ISSUE_FACTORY_USER`, else
2. the plugin’s configured **Technical cron user**, else
3. `administrator`.

### Example crontab

Run every day at 06:00 as a specific technical user:

```cron
0 6 * * * LINKED_ISSUE_FACTORY_USER=automation \
  php /var/www/html/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php \
  >> /var/log/linked_issue_factory.log 2>&1
```

Dry run to preview:

```bash
php /var/www/html/plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php --dry-run --verbose
```

### Concurrency / no duplicates

Each due occurrence is **claimed atomically** with a conditional
`UPDATE … SET next_run_at = <new> WHERE id = <id> AND next_run_at = <old>` and an
affected-rows check. If two cron runs start simultaneously, only one wins the
claim, so an occurrence can never be created twice. After a successful creation
the cron advances `next_run_at`, increments `occurrences_created`, records
`last_created_bug_id`, and **deactivates** the schedule once its end date or
maximum occurrences is reached.

---

## Custom-field handling

When copying custom fields the plugin applies these rules:

1. If the **same custom field id** is linked to the target project → copy.
2. Otherwise, if a target field has the **same name and a compatible type** →
   copy (schedule strategy *linked_or_name*; can be restricted to
   *linked_only*).
3. If the field is not present or not writable in the target → skip.
4. If the value is invalid for the target field (e.g. not an allowed list
   option) → skip.
5. **Required** custom fields of the target project are shown on the form and
   must be completed before the ticket can be created.

Validation and writing go through the MantisBT custom-field APIs
(`custom_field_validate`, `IssueAddCommand`), never raw SQL. An invalid target
field therefore never crashes the plugin – it is skipped or reported.

---

## Permissions & security

* You must be able to **view** the source ticket.
* You must be able to **report** in the target project
  (`report_bug_threshold`); only such projects are offered.
* Custom fields are only read/written where you have the corresponding access.
* Assignees are only offered / accepted where they may handle issues in the
  target project.
* The **Configuration**, **Schedules** and **Dashboard** management pages require
  `manage_plugin_threshold`.
* All POST forms are protected with MantisBT’s CSRF tokens
  (`form_security_*`).
* All output is HTML-escaped via the core string APIs; user input is never put
  into SQL directly – parameterised queries are used for the plugin’s own table
  and core APIs for everything else.
* The cron creates tickets as a dedicated technical user.

---

## Database

The plugin creates a single table (default name shown for the `mantis` prefix):

`mantis_linked_issue_factory_schedule` – one row per recurring schedule, holding
the template text, the standard/custom-field snapshots, the recurrence rule,
the run bookkeeping (`next_run_at`, `occurrences_created`,
`last_created_bug_id`) and the active flag.

The table is created and versioned through the plugin’s `schema()` method.
Uninstalling the plugin from the plugin manager drops it again.

---

## Limitations

* **Parent/child** relationships are modelled with MantisBT’s
  `BUG_DEPENDANT` / `BUG_BLOCKS` pair (MantisBT has no separate parent/child
  type); labels come straight from the core relationship descriptions.
* Recurring creation depends on the **snapshot** taken when the schedule is
  saved. If the target project has **required** custom fields that cannot be
  satisfied from the snapshot, that occurrence is logged as an error and
  skipped (the schedule stays active and advances to the next date).
* The cron does not send the interactive “report more” follow-ups; it only
  creates the ticket, relationship and notes.
* Schedule overview/management is intended for administrators
  (`manage_plugin_threshold`).

---

## License

MIT. Architecturally modelled on the
[Reveille](https://github.com/marcwoge/reveille) plugin.
