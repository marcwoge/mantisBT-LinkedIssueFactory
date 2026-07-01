# LinkedIssueFactory – manual test guide

A checklist for verifying the plugin by hand in a running MantisBT. The
automated smoke tests in the local Docker sandbox already cover the same paths
(see the repository’s `docker/` folder), but these steps let you confirm the
behaviour through the real UI.

Assumes you have at least **two projects** (a source and a target), each with a
category, and one custom field linked to both projects. Log in as a user with
`manage_plugin_threshold` for the admin parts, plus a normal reporter for the
permission checks.

## 0. Installation

1. Copy the plugin to `<mantis>/plugins/LinkedIssueFactory/`.
2. **Manage → Manage Plugins → Install** next to *Linked Issue Factory*.
3. ✅ The plugin appears under installed plugins.
4. ✅ The table `mantis_linked_issue_factory_schedule` exists in the database.
5. ✅ **Manage** shows *Linked Issue Factory* entries (Configuration, Schedules,
   Dashboard).

## 1. Issue-view button

1. Open any ticket.
2. ✅ A **Create linked ticket** button is shown in the action bar.
3. Click it → ✅ the create form opens with `bug_id` pre-set.

## 2. Create once

1. On the create form, ✅ summary / description / steps / additional information
   are pre-filled from the source ticket.
2. Choose a **different** target project → ✅ the form reloads and shows that
   project’s categories and custom fields.
3. ✅ Category is pre-selected to the same-named category if present.
4. ✅ The linked custom field is pre-filled from the source value.
5. Leave the relationship at the default → ✅ default is *related to*.
6. Click **Create ticket now**.
7. ✅ You are redirected to the new ticket in the target project.
8. ✅ The new ticket shows a *related to* relationship to the source.
9. ✅ The custom-field value was copied.
10. ✅ The source ticket has an internal note “a linked issue #N was created…”.
11. ✅ The new ticket has a note “this issue was created from source issue #M”.

## 3. Custom-field edge cases

1. Add a **required** custom field to the target project.
2. Open the create form for that target → ✅ the required field is shown with a
   `*` and pre-filled where possible.
3. Clear it and submit → ✅ a friendly error page explains the missing field;
   nothing is created.
4. Fill it and submit → ✅ ticket is created with the value.
5. Choose a target project **without** the source’s custom field → ✅ no crash;
   the field is simply skipped.

## 4. Permissions

1. As a user who **cannot report** in any project, open the create form → ✅ a
   warning is shown and no create form appears.
2. As a reporter, ✅ the target-project dropdown only lists projects you may
   report in.
3. As a non-admin, browse to
   `plugin.php?page=LinkedIssueFactory/config` → ✅ access is denied.

## 5. Plan a recurrence

1. From the create form, click **Plan recurrence…**.
2. ✅ The schedule form opens, template fields pre-filled.
3. Set **daily**, start = today, max occurrences = 2, active = on. Save.
4. ✅ **Manage → Linked Issue Factory → Schedules** lists the new schedule
   (active, next run today).
5. ✅ **Dashboard** shows the upcoming creation.

## 6. Cron – dry run

```bash
php plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php --dry-run --verbose
```

1. ✅ Output lists the due schedule as “WOULD create…”.
2. ✅ No new ticket is created; the schedule’s `next_run_at` is unchanged.

## 7. Cron – real run

```bash
php plugins/LinkedIssueFactory/scripts/linked_issue_factory_cron.php --verbose
```

1. ✅ A new ticket is created in the target project.
2. ✅ It is linked to the configured source/last ticket.
3. ✅ Both tickets get the cross-reference notes.
4. ✅ The schedule’s `next_run_at` advances, `occurrences_created` is 1,
   `last_created_bug_id` is set.
5. Run again immediately → ✅ nothing happens (next run is in the future).

## 8. Deactivation

1. Make the schedule due again (wait a day, or set `next_run_at` to the past for
   testing).
2. Run the cron → ✅ the second occurrence is created and the schedule is
   **deactivated** (max occurrences reached); `next_run_at` becomes 0.

## 9. Schedule management

1. In the schedule list, ✅ **Activate/Deactivate** toggles the state.
2. ✅ **Edit** reopens the schedule form with stored values.
3. ✅ **Delete** (with confirmation) removes the schedule.

## 10. Safety

1. ✅ Submitting any form without a valid CSRF token is rejected.
2. ✅ All displayed field values are HTML-escaped (try a summary containing
   `<b>` in the source ticket).
