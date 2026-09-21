# Course Escalation Reminder

[![Moodle Plugin CI](https://github.com/krishnaGuptaGit/moodle-local_course_reminder/actions/workflows/ci.yml/badge.svg)](https://github.com/krishnaGuptaGit/moodle-local_course_reminder/actions/workflows/ci.yml)

A Moodle local plugin that sends automated email reminders when enrolled courses are not completed. It has four independent reminder features:

- **Manager Escalation** — notifies the employee's reporting manager about incomplete courses
- **Student Reminder** — notifies the student directly when they have not completed a course (regardless of whether they have started it or not)
- **Course Expiry Reminder** — warns the participant that a course is approaching its end date
- **Course Overdue Reminder** — tells the participant a course end date has passed, copying their reporting manager

The first two are driven by how long ago the learner enrolled. The last two are driven by the **course end date**, and apply only to courses that have one.

## Requirements

- Moodle 4.4 – 5.2
- Course completion tracking enabled on target courses
- Custom user profile fields (required for Manager Escalation only):
  - `reporting_manager_email` — manager's email address
  - `reporting_manager_name` — manager's display name (optional, defaults to "Manager")
- The manager must exist as a Moodle user for escalation emails to be delivered

## Installation

1. Copy the `course_reminder` folder into the `local/` plugin directory of your Moodle installation:
   - **Moodle 4.4 and 5.0** — `local/course_reminder/`
   - **Moodle 5.1 and 5.2** — `public/local/course_reminder/` (5.1 moved the Moodle codebase under `public/`)

   If in doubt, use the `local/` directory that sits alongside `lib/` and `admin/`.
2. Visit **Site administration > Notifications** to trigger the plugin installation (creates the `local_course_reminder_log` database table).
3. Configure the plugin under **Site administration > Plugins > Local plugins > Course Escalation Reminder**.

## Configuration

### Global

| Setting | Description | Default |
|---|---|---|
| Enable Plugin | Master switch — disables all features when off | Off |
| Processing Start Date | HTML5 date picker (range: 2 years back to 1 year forward). For the Manager and Student reminders, only enrolments created/started on or after this date are processed. For the Expiry and Overdue reminders it instead bounds the **course end date**, so switching Overdue on does not sweep in every course that has ever ended. Leave blank to disable both guards. | Blank (disabled) |
| Excluded Course Categories | Searchable multi-select dropdown listing all course categories. Type to filter, click to add; each selected category appears as a removable chip. Courses in selected categories — and all their sub-categories — are excluded from all reminders. Leave blank to exclude no categories. | None |

### Manager Escalation

| Setting | Description | Default |
|---|---|---|
| Enable Manager Escalation Reminders | Turn this feature on or off independently | Off |
| Manager Reminder Days | Days after enrollment before the first escalation email is sent | 7 |
| Manager Reminder Cycle Days | Days between follow-up reminders after the first has been sent | 7 |
| Email Type | `Individual` (one email per learner) or `Consolidated` (one email per manager) | Individual |
| Email Subject/Body | Customizable templates for both email types | See below |

**Template variables — Individual:** `{coursename}`, `{username}`, `{managername}`, `{days}`, `{enrolleddays}`, `{sitename}`

> `{days}` = the configured reminder threshold; `{enrolleddays}` = the actual number of days the learner has been enrolled (useful when the learner enrolled long before the threshold was reached).

**Template variables — Consolidated:** `{managername}`, `{employeelist}`, `{sitename}`

> **HTML in templates:** Body fields accept HTML. The default templates include a generic login link `<a href="#" target="_blank">LMS</a>` — replace `#` with your actual LMS URL before enabling the plugin. Plain-text email clients automatically receive a tag-stripped fallback.

### Student Reminder

| Setting | Description | Default |
|---|---|---|
| Enable Student Reminders | Turn this feature on or off independently | Off |
| Student Reminder Days | Days after enrollment before the first reminder is sent | 7 |
| Student Reminder Cycle Days | Days between follow-up reminders after the first has been sent | 7 |
| Email Type | `Individual` (one email per course) or `Consolidated` (one email listing all incomplete courses) | Individual |
| Email Subject/Body | Customizable templates for both email types | See below |

**Template variables — Individual:** `{coursename}`, `{username}`, `{days}`, `{enrolleddays}`, `{sitename}`

> `{days}` = the configured reminder threshold; `{enrolleddays}` = the actual number of days the learner has been enrolled.

**Template variables — Consolidated:** `{username}`, `{courselist}`, `{days}`, `{sitename}`

> **HTML in templates:** Body fields accept HTML. The default templates include a generic login link `<a href="#" target="_blank">LMS</a>` — replace `#` with your actual LMS URL before enabling the plugin. Plain-text email clients automatically receive a tag-stripped fallback.

### Course Expiry & Overdue Reminders

These two reminders are driven by the **course end date**, not by the enrolment date. A course with no end date is never included in either. Both are skipped for anyone who has already completed the course, and both respect Excluded Course Categories.

| Setting | Description | Default |
|---|---|---|
| Enable Expiry Reminders | Turn the pre-deadline warning on or off | Off |
| Days Before Expiry | How many days before the course end date to warn the participant. Example: set to 7 for a course ending 10 Oct → sent on 3 Oct. | 7 |
| Enable Overdue Reminders | Turn the post-deadline notice on or off | Off |
| Days After End Date | How many days after the course end date to send the overdue notice. The end date day itself is not counted. Example: set to 3 for a course that ended 1 Oct → sent on 4 Oct. | 7 |
| Email Subject/Body | Customizable template for each reminder | See settings |

**Template variables — Expiry:** `{coursename}`, `{username}`, `{enddate}`, `{days}`, `{daysremaining}`, `{sitename}`

**Template variables — Overdue:** `{coursename}`, `{username}`, `{managername}`, `{enddate}`, `{days}`, `{daysoverdue}`, `{sitename}`

> `{days}` = the configured threshold; `{daysremaining}` = actual days left before the end date; `{daysoverdue}` = actual days since the end date.

**Each is sent once per deadline.** A participant receives one expiry warning and one overdue notice per course. If an administrator later changes the course end date, the reminder re-arms and fires once more for the new deadline.

**Manager copy on overdue.** The overdue email goes to the participant with their reporting manager copied on the same message, taken from the `reporting_manager_email` profile field. This happens **whether or not Manager Escalation Reminders is enabled** — the two features are independent. If the manager address is missing or invalid the participant still receives the reminder on their own; a bad manager address never blocks the send. The expiry reminder and the student reminder do not copy anyone.

## Day Counting Rules (Exclusion-Based)

Both **Reminder Days** and **Cycle Days** use exclusion-based counting — the starting day (enrollment day or previous reminder day) is not counted.

| Scenario | Example |
|---|---|
| Reminder Days = 3, enrolled 1 Apr | First reminder sent on **4 Apr** |
| Cycle Days = 2, first reminder 4 Apr | Second reminder sent on **6 Apr** |
| Cycle Days = 1, first reminder 4 Apr | Second reminder sent on **5 Apr** (daily) |

## How It Works

1. A scheduled task runs daily at 17:00 server time.
2. If the global **Enable Plugin** setting is off, the task exits immediately.
3. If **Processing Start Date** is set, enrolments created/started before that date are excluded at the SQL level.
4. If **Excluded Course Categories** is set, courses in those categories (and all sub-categories) are excluded at the SQL level before any row is processed — they do not appear in skip counters.

### Manager Escalation

1. Finds all active enrollments where the enrollment date is at least **Manager Reminder Days** before today (exclusion-based), excluding any courses in the configured excluded categories.
2. For each enrollment, skips if:
   - The course does not have completion tracking enabled.
   - The learner has already completed the course.
   - The learner has no `reporting_manager_email` profile field set.
   - The manager does not exist as a Moodle user.
   - A reminder was already sent and the **Manager Reminder Cycle Days** interval has not yet elapsed.
3. Depending on **Email Type**, sends individual emails per learner or one consolidated email per manager.
4. After each send, records the timestamp in `local_course_reminder_log`. Subsequent runs use this log to enforce the cycle interval and avoid re-sending before the cycle elapses.

### Student Reminder

1. Finds all active enrollments where the enrollment date is at least **Student Reminder Days** before today (exclusion-based), excluding any courses in the configured excluded categories.
2. For each enrollment, skips if:
   - The learner has already completed the course.
   - A reminder was already sent and the **Student Reminder Cycle Days** interval has not yet elapsed.
3. Reminders are sent to **all** incomplete learners — both those who have started the course and those who have not yet opened it.
4. Depending on **Email Type**, sends one email per incomplete course or one consolidated email per student listing all courses requiring attention.
5. After each send, records the timestamp in `local_course_reminder_log`.

> **Duplicate prevention:** When a learner is enrolled in the same course via multiple enrolment methods, the course appears only once in consolidated emails.

### Course Expiry Reminder

1. Finds active enrolments on courses that **have an end date** falling within the configured notice period, excluding any courses in the configured excluded categories.
2. Skips the course if completion tracking is not enabled, if the learner has already completed it, or if an expiry reminder has already been sent for that same end date.
3. Sends one email per course to the participant. The manager is not copied.

### Course Overdue Reminder

1. Finds active enrolments on courses that **have an end date** which passed at least the configured number of days ago, excluding any courses in the configured excluded categories.
2. Skips the course if completion tracking is not enabled, if the learner has already completed it, or if an overdue reminder has already been sent for that same end date.
3. Sends one email per course to the participant, copying their reporting manager when an address is available.

> Because enrolment durations are often tied to the course end date, the overdue reminder accepts enrolments that were valid **as at the course end date**, rather than requiring them to still be running today. Without this it would miss most of the learners it exists to chase.

## Performance / Scale

The task makes approximately 4–5 database queries per enrollment row processed (course completion check, completion-enabled check, manager profile field lookups, reminder log lookup). For most deployments this is not a concern, but as a guide:

- **Up to ~5,000 active incomplete enrollments per run** — fully acceptable.
- **Above ~5,000** — the total query count grows linearly. Monitor task execution time and consider contacting the plugin maintainer if runtime becomes excessive.

All JOIN conditions in the main enrollment query are covered by standard Moodle indexes. The `local_course_reminder_log` table has a unique composite index on `(userid, courseid, remindertype)`.

## File Structure

```
course_reminder/
├── classes/
│   ├── admin/
│   │   └── admin_setting_configdate.php  # Custom HTML5 date picker admin setting
│   ├── privacy/
│   │   └── provider.php                  # Privacy API — data export and deletion
│   └── task/
│       ├── seed_reminder_log_task.php    # Adhoc task — seeds log after install/upgrade
│       └── send_reminder_task.php        # Scheduled task — daily reminder logic
├── db/
│   ├── install.php                       # Post-install hook — queues seed adhoc task
│   ├── install.xml                       # Database schema (fresh installs)
│   ├── tasks.php                         # Task registration (daily at 17:00)
│   └── upgrade.php                       # Database migration (existing installs)
├── lang/en/local_course_reminder.php     # Language strings
├── settings.php                          # Admin settings page
└── version.php                           # Plugin metadata
```

## License

This plugin is licensed under the [GNU GPL v3 or later](https://www.gnu.org/copyleft/gpl.html).
