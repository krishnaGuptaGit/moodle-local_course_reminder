<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task class for sending course reminder emails.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_course_reminder\task;

use core\task\scheduled_task;
use core_user;
use stdClass;

/**
 * Sends automated email reminders for incomplete course enrolments.
 *
 * Two independent reminder modes are supported: manager escalation (notifies the
 * employee's reporting manager) and student reminder (notifies the learner directly).
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_reminder_task extends scheduled_task {
    /**
     * Returns the human-readable task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskname', 'local_course_reminder');
    }

    /**
     * Executes the scheduled task.
     *
     * Checks the global enable switch, then runs manager escalation and student
     * reminder pipelines independently based on their individual enable settings.
     *
     * @return void
     */
    public function execute() {
        // Global master switch — exits immediately if off.
        $enabled = get_config('local_course_reminder', 'enable');
        if (!$enabled) {
            mtrace('Course reminder plugin is disabled. Exiting.');
            return;
        }

        // Parse and expand excluded categories once — passed to both reminder paths.
        $excludedstr    = get_config('local_course_reminder', 'excluded_categoryids');
        $selectedcatids = [];
        if (!empty($excludedstr)) {
            $selectedcatids = array_values(array_filter(
                array_map('intval', explode(',', $excludedstr))
            ));
        }
        $excludedcategoryids = $this->get_all_excluded_category_ids($selectedcatids);

        $managerenabled = get_config('local_course_reminder', 'manager_enable');
        if ($managerenabled) {
            $this->process_manager_reminders($excludedcategoryids);
        }

        $studentreminderenabled = get_config('local_course_reminder', 'student_enable');
        if ($studentreminderenabled) {
            $studentdays = (int) get_config('local_course_reminder', 'student_days');
            if ($studentdays <= 0) {
                $studentdays = 7;
            }
            $this->process_student_reminders($studentdays, $excludedcategoryids);
        }

        $expiryenabled = get_config('local_course_reminder', 'expiry_enable');
        if ($expiryenabled) {
            $expirydays = (int) get_config('local_course_reminder', 'expiry_days');
            if ($expirydays <= 0) {
                $expirydays = 7;
            }
            $this->process_expiry_reminders($expirydays, $excludedcategoryids);
        }

        $overdueenabled = get_config('local_course_reminder', 'overdue_enable');
        if ($overdueenabled) {
            $overduedays = (int) get_config('local_course_reminder', 'overdue_days');
            if ($overduedays <= 0) {
                $overduedays = 7;
            }
            $this->process_overdue_reminders($overduedays, $excludedcategoryids);
        }
    }

    /**
     * Runs the full manager escalation reminder pipeline.
     *
     * Queries all active enrolments past the configured threshold, checks completion
     * and cycle state, then sends individual or consolidated emails to managers.
     *
     * @param int[] $excludedcategoryids Category IDs (including descendants) to exclude.
     * @return void
     */
    private function process_manager_reminders(array $excludedcategoryids): void {
        global $DB;

        $days = (int) get_config('local_course_reminder', 'manager_days');
        if ($days <= 0) {
            $days = 7;
        }

        $cycledays = (int) get_config('local_course_reminder', 'manager_cycledays');
        if ($cycledays <= 0) {
            $cycledays = 7;
        }

        $emailtype = get_config('local_course_reminder', 'manager_emailtype');
        if (empty($emailtype)) {
            $emailtype = 'individual';
        }

        // Exclusion-based cutoff: enrollment day itself is not counted.
        // Example: days=3, enrolled 1 Apr — first reminder fires 4 Apr.
        // Cutoffend = midnight of (today - days + 1); timestart must be strictly before that.
        //
        // Note: strtotime('today midnight') resolves relative to the server's configured
        // timezone (php.ini date.timezone). UTC is recommended to avoid DST-related drift.
        $now               = time();
        $todaymidnight     = strtotime('today midnight');
        $cutoffend         = $todaymidnight - (($days - 1) * 86400);
        $processstartstr  = get_config('local_course_reminder', 'processing_start_date');
        $processstartdate = 0;
        if (!empty($processstartstr)) {
            $parseddate = \DateTime::createFromFormat('Y-m-d', $processstartstr);
            if ($parseddate && $parseddate->format('Y-m-d') === $processstartstr) {
                $processstartdate = (int) strtotime($processstartstr . ' 00:00:00 UTC');
            } else {
                mtrace('Warning: Processing Start Date "' . $processstartstr
                    . '" is not a valid YYYY-MM-DD date. Guard disabled — all enrolments will be processed.');
            }
        }

        $excludeclause = '';
        $excludeparams = [];
        if (!empty($excludedcategoryids)) {
            [$excludeclause, $excludeparams] = $DB->get_in_or_equal(
                $excludedcategoryids,
                SQL_PARAMS_NAMED,
                'exccat',
                false
            );
            $excludeclause = 'AND c.category ' . $excludeclause;
            mtrace('Excluding courses in ' . count($excludedcategoryids)
                . ' category IDs (selected + sub-categories).');
        }

        $sql = "SELECT ue.id, ue.userid, ue.enrolid, e.courseid, c.fullname as coursename,
                       u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename,
                       COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) AS timestart
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                JOIN {course_categories} cc ON cc.id = c.category
                JOIN {user} u ON u.id = ue.userid
                WHERE COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) < :cutoffend
                  AND COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) >= :processstartdate
                  AND (ue.timeend = 0 OR ue.timeend > :now)
                  AND ue.status = 0
                  AND e.status = 0
                  AND u.deleted = 0
                  AND u.suspended = 0
                  AND u.confirmed = 1
                  AND c.visible = 1
                  AND c.id != 1
                  AND cc.visible = 1
                  AND (c.startdate = 0 OR c.startdate <= :nowstart)
                  AND (c.enddate = 0 OR c.enddate > :nowend)
                  {$excludeclause}";

        $enrollments = $DB->get_recordset_sql($sql, array_merge([
            'cutoffend'        => $cutoffend,
            'processstartdate' => $processstartdate,
            'now'              => $now,
            'nowstart'         => $now,
            'nowend'           => $now,
        ], $excludeparams));

        $processed = $emailssent = $skippedcompleted = 0;
        $skippednocompletion = $skippednomanager = $skippednotmoodleuser = $skippednotdue = 0;

        $pendingreminders   = [];
        $seenmanagercourses = [];

        foreach ($enrollments as $enrollment) {
            try {
                if ($this->is_course_completed($enrollment->userid, $enrollment->courseid)) {
                    $skippedcompleted++;
                    $processed++;
                    continue;
                }

                if (!$this->is_completion_enabled($enrollment->courseid)) {
                    $skippednocompletion++;
                    $processed++;
                    continue;
                }

                $manager = $this->get_manager_data($enrollment->userid);

                if (empty($manager) || empty($manager->manager_email)) {
                    mtrace("Debug: No manager - Employee: {$enrollment->email}");
                    $skippednomanager++;
                    $processed++;
                    continue;
                }

                $logrecord = $DB->get_record('local_course_reminder_log', [
                    'userid'       => $enrollment->userid,
                    'courseid'     => $enrollment->courseid,
                    'remindertype' => 'manager',
                ]);

                // Cycle check: fires when dayssince >= cycledays.
                // Setting cycledays=1 sends daily; cycledays=2 every 2 days; cycledays=7 weekly.
                // Example: cycledays=2, last reminder 4 Apr — next fires 6 Apr.
                if ($logrecord) {
                    $lastsentmidnight = strtotime('midnight', $logrecord->timesent);
                    $dayssince = (int)(($todaymidnight - $lastsentmidnight) / 86400);
                    $shouldsend = $dayssince >= $cycledays;
                } else {
                    $shouldsend = true;
                }

                if (!$shouldsend) {
                    $skippednotdue++;
                    $processed++;
                    continue;
                }

                $enrollment->enrolleddays = (int) floor((time() - $enrollment->timestart) / 86400);
                $enrollment->employeename = fullname($enrollment);
                $enrollment->logrecord    = $logrecord;

                if ($emailtype === 'consolidated') {
                    $key = $manager->manager_email;
                    // Dedup on (manager_email, userid, courseid): prevents the same employee+course
                    // appearing twice (e.g. enrolled via manual + cohort), but allows different
                    // employees enrolled in the same course to each appear in the manager's email.
                    if (isset($seenmanagercourses[$key][$enrollment->userid][$enrollment->courseid])) {
                        $processed++;
                        continue;
                    }
                    $seenmanagercourses[$key][$enrollment->userid][$enrollment->courseid] = true;

                    if (!isset($pendingreminders[$key])) {
                        $pendingreminders[$key] = ['manager' => $manager, 'enrollments' => []];
                    }
                    $pendingreminders[$key]['enrollments'][] = $enrollment;
                } else {
                    $result = $this->send_individual_email($manager, $enrollment, $days);
                    if ($result === 'notmoodleuser') {
                        $skippednotmoodleuser++;
                    } else if ($result === 'sent') {
                        $this->upsert_log($enrollment->userid, $enrollment->courseid, 'manager', $logrecord);
                        $emailssent++;
                    } else {
                        mtrace('Warning: Failed to send manager reminder email'
                            . " to {$manager->manager_email} for employee {$enrollment->email}");
                    }
                }
                $processed++;
            } catch (\Exception $e) {
                mtrace("Error processing enrollment {$enrollment->id}: " . $e->getMessage());
                $processed++;
            }
        }

        $enrollments->close();

        if ($emailtype === 'consolidated' && !empty($pendingreminders)) {
            foreach ($pendingreminders as $data) {
                $manager         = $data['manager'];
                $enrollmentslist = $data['enrollments'];

                usort($enrollmentslist, function ($a, $b) {
                    return strcasecmp($a->employeename, $b->employeename);
                });

                $manageruser = core_user::get_user_by_email($manager->manager_email);

                if (!$manageruser) {
                    $emails = array_map(function ($e) {
                        return $e->email;
                    }, $enrollmentslist);
                    mtrace('Debug: Manager not in Moodle - Employee emails: ' . implode(', ', $emails));
                    $skippednotmoodleuser += count($enrollmentslist);
                    continue;
                }

                $sent = $this->send_consolidated_email($manager, $enrollmentslist);

                if ($sent) {
                    foreach ($enrollmentslist as $enrollment) {
                        $this->upsert_log(
                            $enrollment->userid,
                            $enrollment->courseid,
                            'manager',
                            $enrollment->logrecord
                        );
                    }
                    $emailssent++;
                }
            }
        }

        mtrace('Manager escalation reminder task completed.');
        mtrace("Total processed: {$processed}");
        mtrace("Emails sent: {$emailssent}");
        mtrace("Skipped (already completed): {$skippedcompleted}");
        mtrace("Skipped (completion not enabled): {$skippednocompletion}");
        mtrace("Skipped (no manager): {$skippednomanager}");
        mtrace("Skipped (manager not Moodle user): {$skippednotmoodleuser}");
        mtrace("Skipped (reminder not yet due): {$skippednotdue}");
    }

    /**
     * Checks whether a user has completed a course.
     *
     * @param int $userid   The user ID.
     * @param int $courseid The course ID.
     * @return bool True if the course has been completed.
     */
    private function is_course_completed($userid, $courseid) {
        global $DB;

        $completion = $DB->get_record('course_completions', [
            'userid' => $userid,
            'course' => $courseid,
        ]);

        return ($completion && $completion->timecompleted);
    }

    /**
     * Checks whether completion tracking is enabled for a course.
     *
     * @param int $courseid The course ID.
     * @return bool True if completion tracking is enabled.
     */
    private function is_completion_enabled($courseid) {
        global $DB;

        $enablecompletion = $DB->get_field('course', 'enablecompletion', ['id' => $courseid]);

        return !empty($enablecompletion);
    }

    /**
     * Retrieves manager email and display name from the user's custom profile fields.
     *
     * @param int $userid The employee's user ID.
     * @return stdClass|null Manager data object with manager_email and manager_name, or null.
     */
    private function get_manager_data($userid) {
        global $DB;

        $managerfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'reporting_manager_email']);

        if (!$managerfieldid) {
            return null;
        }

        $manageremail = $DB->get_field('user_info_data', 'data', [
            'userid'  => $userid,
            'fieldid' => $managerfieldid,
        ]);

        if (empty($manageremail)) {
            return null;
        }

        if (!validate_email($manageremail)) {
            return null;
        }

        $managerfieldidname = $DB->get_field('user_info_field', 'id', ['shortname' => 'reporting_manager_name']);
        $managername = '';

        if ($managerfieldidname) {
            $managername = $DB->get_field('user_info_data', 'data', [
                'userid'  => $userid,
                'fieldid' => $managerfieldidname,
            ]);
        }

        $manager = new stdClass();
        $manager->manager_email = $manageremail;
        $manager->manager_name  = $managername ?: 'Manager';

        return $manager;
    }

    /**
     * Inserts or updates the reminder log record after a successful send.
     *
     * @param int         $userid       The user ID.
     * @param int         $courseid     The course ID.
     * @param string      $remindertype The reminder type ('manager', 'student', 'expiry' or 'overdue').
     * @param object|null $logrecord    Existing DB record if any, null for first send.
     * @param int|null    $refdate      Course end date this reminder relates to. Null for the
     *                                  cycle-based manager and student reminders, which are not
     *                                  tied to a particular deadline.
     * @return void
     */
    private function upsert_log($userid, $courseid, $remindertype, $logrecord, $refdate = null) {
        global $DB;

        if ($logrecord) {
            $logrecord->timesent = time();
            $logrecord->refdate  = $refdate;
            $DB->update_record('local_course_reminder_log', $logrecord);
        } else {
            $newrecord = new stdClass();
            $newrecord->userid       = $userid;
            $newrecord->courseid     = $courseid;
            $newrecord->remindertype = $remindertype;
            $newrecord->timesent     = time();
            $newrecord->refdate      = $refdate;
            $DB->insert_record('local_course_reminder_log', $newrecord);
        }
    }

    /**
     * Sends an individual manager escalation email for one learner.
     *
     * @param stdClass $manager    Manager data (manager_email, manager_name).
     * @param stdClass $enrollment Enrollment row with coursename, employeename, enrolleddays.
     * @param int      $days       Configured reminder threshold in days.
     * @return string|null 'sent', 'failed', 'notmoodleuser', or null if skipped.
     */
    private function send_individual_email($manager, $enrollment, $days) {
        global $DB;

        if (empty($manager->manager_email) || empty($manager->manager_name)) {
            return null;
        }

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'manager_emailsubjectindividual');
        if (empty($subjecttemplate)) {
            $subjecttemplate = get_string('manager_emailsubjectindividual_default', 'local_course_reminder');
        }

        $bodytemplate = get_config('local_course_reminder', 'manager_emailbodyindividual');
        if (empty($bodytemplate)) {
            $bodytemplate = get_string('manager_emailbodyindividual_default', 'local_course_reminder');
        }

        $replacements = [
            '{coursename}'   => $enrollment->coursename,
            '{username}'     => $enrollment->employeename,
            '{managername}'  => $manager->manager_name,
            '{days}'         => $days,
            '{enrolleddays}' => $enrollment->enrolleddays,
            '{sitename}'     => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $manageruser = core_user::get_user_by_email($manager->manager_email);

        if (!$manageruser) {
            mtrace("Debug: Manager not in Moodle - Employee: {$enrollment->email},"
                . " Manager: {$manager->manager_email}");
            return 'notmoodleuser';
        }

        $noreplyuser = core_user::get_noreply_user();

        $sent = email_to_user(
            $manageruser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
            '',
            true
        );

        return $sent ? 'sent' : 'failed';
    }

    /**
     * Sends a consolidated manager escalation email covering all incomplete learners.
     *
     * @param stdClass $manager     Manager data (manager_email, manager_name).
     * @param array    $enrollments List of enrollment rows to include in the email.
     * @return bool True if the email was sent successfully.
     */
    private function send_consolidated_email($manager, $enrollments) {
        global $DB;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'manager_emailsubjectconsolidated');
        if (empty($subjecttemplate)) {
            $subjecttemplate = get_string('manager_emailsubjectconsolidated_default', 'local_course_reminder');
        }

        $bodytemplate = get_config('local_course_reminder', 'manager_emailbodyconsolidated');
        if (empty($bodytemplate)) {
            $bodytemplate = get_string('manager_emailbodyconsolidated_default', 'local_course_reminder');
        }

        $employeelist = '';
        $counter = 1;
        foreach ($enrollments as $enrollment) {
            $employeelist .= "{$counter}. {$enrollment->employeename} - {$enrollment->coursename}\n";
            $counter++;
        }

        $replacements = [
            '{managername}'  => $manager->manager_name,
            '{employeelist}' => $employeelist,
            '{sitename}'     => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $manageruser = core_user::get_user_by_email($manager->manager_email);
        $noreplyuser = core_user::get_noreply_user();

        $sent = email_to_user(
            $manageruser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
            '',
            true
        );

        return $sent;
    }

    /**
     * Runs the full student reminder pipeline.
     *
     * Queries all active enrolments past the configured threshold, checks completion
     * and cycle state, then sends individual or consolidated emails to students.
     *
     * @param int   $studentdays         Configured reminder threshold in days.
     * @param int[] $excludedcategoryids Category IDs (including descendants) to exclude.
     * @return void
     */
    private function process_student_reminders(int $studentdays, array $excludedcategoryids): void {
        global $DB;

        $cycledays = (int) get_config('local_course_reminder', 'student_cycledays');
        if ($cycledays <= 0) {
            $cycledays = 7;
        }

        $emailtype = get_config('local_course_reminder', 'student_emailtype');
        if (empty($emailtype)) {
            $emailtype = 'individual';
        }

        // Exclusion-based cutoff: enrollment day itself is not counted.
        // Example: days=3, enrolled 1 Apr — first reminder fires 4 Apr.
        //
        // Note: strtotime('today midnight') resolves relative to the server's configured
        // timezone (php.ini date.timezone). UTC is recommended to avoid DST-related drift.
        $now              = time();
        $todaymidnight    = strtotime('today midnight');
        $cutoffend        = $todaymidnight - (($studentdays - 1) * 86400);
        $processstartstr  = get_config('local_course_reminder', 'processing_start_date');
        $processstartdate = 0;
        if (!empty($processstartstr)) {
            $parseddate = \DateTime::createFromFormat('Y-m-d', $processstartstr);
            if ($parseddate && $parseddate->format('Y-m-d') === $processstartstr) {
                $processstartdate = (int) strtotime($processstartstr . ' 00:00:00 UTC');
            } else {
                mtrace('Warning: Processing Start Date "' . $processstartstr
                    . '" is not a valid YYYY-MM-DD date. Guard disabled — all enrolments will be processed.');
            }
        }

        $excludeclause = '';
        $excludeparams = [];
        if (!empty($excludedcategoryids)) {
            [$excludeclause, $excludeparams] = $DB->get_in_or_equal(
                $excludedcategoryids,
                SQL_PARAMS_NAMED,
                'exccat',
                false
            );
            $excludeclause = 'AND c.category ' . $excludeclause;
            mtrace('Excluding courses in ' . count($excludedcategoryids)
                . ' category IDs (selected + sub-categories).');
        }

        $sql = "SELECT ue.id, ue.userid, ue.enrolid, e.courseid, c.fullname as coursename,
                       u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename,
                       COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) AS timestart
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                JOIN {course_categories} cc ON cc.id = c.category
                JOIN {user} u ON u.id = ue.userid
                WHERE COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) < :cutoffend
                  AND COALESCE(NULLIF(ue.timestart, 0), ue.timecreated) >= :processstartdate
                  AND (ue.timeend = 0 OR ue.timeend > :now)
                  AND ue.status = 0
                  AND e.status = 0
                  AND u.deleted = 0
                  AND u.suspended = 0
                  AND u.confirmed = 1
                  AND c.visible = 1
                  AND c.id != 1
                  AND cc.visible = 1
                  AND (c.startdate = 0 OR c.startdate <= :nowstart)
                  AND (c.enddate = 0 OR c.enddate > :nowend)
                  {$excludeclause}";

        $params = array_merge([
            'cutoffend'        => $cutoffend,
            'processstartdate' => $processstartdate,
            'now'              => $now,
            'nowstart'         => $now,
            'nowend'           => $now,
        ], $excludeparams);

        $enrollments = $DB->get_recordset_sql($sql, $params);

        $processed           = 0;
        $emailssent          = 0;
        $skippedcompleted    = 0;
        $skippednocompletion = 0;
        $skippednotdue       = 0;

        $pendingstudentreminders = [];
        $seenstudentcourses      = [];

        foreach ($enrollments as $enrollment) {
            try {
                // Skip students who have already completed the course.
                if ($this->is_course_completed($enrollment->userid, $enrollment->courseid)) {
                    $skippedcompleted++;
                    $processed++;
                    continue;
                }

                // Skip courses without completion tracking — there is no way to determine
                // completion, so reminders would fire indefinitely.
                if (!$this->is_completion_enabled($enrollment->courseid)) {
                    $skippednocompletion++;
                    $processed++;
                    continue;
                }

                // Reminder logic: send only if first time or cycle has elapsed.
                // Both students with zero activity AND students who started but did not finish are included.
                $logrecord = $DB->get_record('local_course_reminder_log', [
                    'userid'       => $enrollment->userid,
                    'courseid'     => $enrollment->courseid,
                    'remindertype' => 'student',
                ]);

                // Cycle check: fires when dayssince >= cycledays.
                // Setting cycledays=1 sends daily; cycledays=2 every 2 days; cycledays=7 weekly.
                // Example: cycledays=2, last reminder 4 Apr — next fires 6 Apr.
                if ($logrecord) {
                    $lastsentmidnight = strtotime('midnight', $logrecord->timesent);
                    $dayssince = (int)(($todaymidnight - $lastsentmidnight) / 86400);
                    $shouldsend = $dayssince >= $cycledays;
                } else {
                    $shouldsend = true;
                }

                if (!$shouldsend) {
                    $skippednotdue++;
                    $processed++;
                    continue;
                }

                $enrollment->enrolleddays = (int) floor((time() - $enrollment->timestart) / 86400);
                $enrollment->employeename = fullname($enrollment);
                $enrollment->logrecord    = $logrecord;

                $studentuser = core_user::get_user($enrollment->userid);
                if (!$studentuser || $studentuser->deleted || $studentuser->suspended) {
                    $processed++;
                    continue;
                }

                if ($emailtype === 'consolidated') {
                    $key = $enrollment->userid;
                    // Dedup: skip if this (userid, courseid) pair was already queued.
                    if (isset($seenstudentcourses[$key][$enrollment->courseid])) {
                        $processed++;
                        continue;
                    }
                    $seenstudentcourses[$key][$enrollment->courseid] = true;

                    if (!isset($pendingstudentreminders[$key])) {
                        $pendingstudentreminders[$key] = [
                            'studentuser' => $studentuser,
                            'enrollments' => [],
                        ];
                    }
                    $pendingstudentreminders[$key]['enrollments'][] = $enrollment;
                } else {
                    $sent = $this->send_student_email($studentuser, $enrollment, $studentdays);
                    if ($sent) {
                        $this->upsert_log($enrollment->userid, $enrollment->courseid, 'student', $logrecord);
                        $emailssent++;
                    }
                }
                $processed++;
            } catch (\Exception $e) {
                mtrace("Error processing student reminder for enrollment {$enrollment->id}: "
                    . $e->getMessage());
                $processed++;
            }
        }

        $enrollments->close();

        if ($emailtype === 'consolidated' && !empty($pendingstudentreminders)) {
            foreach ($pendingstudentreminders as $key => $data) {
                $studentuser     = $data['studentuser'];
                $enrollmentslist = $data['enrollments'];

                usort($enrollmentslist, function ($a, $b) {
                    return strcasecmp($a->coursename, $b->coursename);
                });

                $sent = $this->send_student_consolidated_email($studentuser, $enrollmentslist, $studentdays);

                if ($sent) {
                    // Log each course covered by this consolidated email.
                    foreach ($enrollmentslist as $enrollment) {
                        $this->upsert_log(
                            $enrollment->userid,
                            $enrollment->courseid,
                            'student',
                            $enrollment->logrecord
                        );
                    }
                    $emailssent++;
                }
            }
        }

        mtrace('Student reminder task completed.');
        mtrace("Total processed: {$processed}");
        mtrace("Student emails sent: {$emailssent}");
        mtrace("Skipped (already completed): {$skippedcompleted}");
        mtrace("Skipped (completion not enabled): {$skippednocompletion}");
        mtrace("Skipped (reminder not yet due): {$skippednotdue}");
    }

    /**
     * Sends an individual student reminder email for one incomplete course.
     *
     * @param stdClass $studentuser  The student's Moodle user object.
     * @param stdClass $enrollment   Enrollment row with coursename, employeename, enrolleddays.
     * @param int      $studentdays  Configured reminder threshold in days.
     * @return bool True if the email was sent successfully.
     */
    private function send_student_email($studentuser, $enrollment, $studentdays) {
        global $DB;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'student_emailsubjectindividual');
        if (empty($subjecttemplate)) {
            $subjecttemplate = get_string('student_emailsubjectindividual_default', 'local_course_reminder');
        }

        $bodytemplate = get_config('local_course_reminder', 'student_emailbodyindividual');
        if (empty($bodytemplate)) {
            $bodytemplate = get_string('student_emailbodyindividual_default', 'local_course_reminder');
        }

        $replacements = [
            '{coursename}'   => $enrollment->coursename,
            '{username}'     => $enrollment->employeename,
            '{days}'         => $studentdays,
            '{enrolleddays}' => $enrollment->enrolleddays,
            '{sitename}'     => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $noreplyuser = core_user::get_noreply_user();

        return email_to_user(
            $studentuser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
            '',
            true
        );
    }

    /**
     * Expands a list of category IDs to include all descendant categories.
     *
     * Uses Moodle's path column (e.g. /1/3/7) to find descendants via LIKE matching.
     * Two DB queries: one to fetch selected categories' paths, one to find descendants.
     *
     * @param int[] $selectedids Category IDs chosen in admin settings.
     * @return int[] Full list of category IDs to exclude (selected + all descendants).
     */
    private function get_all_excluded_category_ids(array $selectedids): array {
        global $DB;

        if (empty($selectedids)) {
            return [];
        }

        // Query 1: fetch paths of the selected categories.
        [$insql, $inparams] = $DB->get_in_or_equal($selectedids, SQL_PARAMS_NAMED, 'selcat');
        $selectedcats = $DB->get_records_sql(
            "SELECT id, path FROM {course_categories} WHERE id {$insql}",
            $inparams
        );

        $allids         = [];
        $pathconditions = [];
        $pathparams     = [];
        $idx            = 0;
        foreach ($selectedcats as $cat) {
            $allids[] = (int) $cat->id;
            if (!empty($cat->path)) {
                $pathconditions[] = $DB->sql_like('path', ':catpath' . $idx);
                $pathparams['catpath' . $idx] = $cat->path . '/%';
                $idx++;
            }
        }

        // Query 2: fetch all descendants via path prefix matching.
        if (!empty($pathconditions)) {
            $descendants = $DB->get_records_sql(
                'SELECT id FROM {course_categories} WHERE ' . implode(' OR ', $pathconditions),
                $pathparams
            );
            foreach ($descendants as $desc) {
                $allids[] = (int) $desc->id;
            }
        }

        return array_values(array_unique($allids));
    }

    /**
     * Sends a consolidated student reminder email listing all incomplete courses.
     *
     * @param stdClass $studentuser  The student's Moodle user object.
     * @param array    $enrollments  List of enrollment rows to include.
     * @param int      $studentdays  Configured reminder threshold in days.
     * @return bool True if the email was sent successfully.
     */
    private function send_student_consolidated_email($studentuser, $enrollments, $studentdays) {
        global $DB;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);

        $subjecttemplate = get_config('local_course_reminder', 'student_emailsubjectconsolidated');
        if (empty($subjecttemplate)) {
            $subjecttemplate = get_string('student_emailsubjectconsolidated_default', 'local_course_reminder');
        }

        $bodytemplate = get_config('local_course_reminder', 'student_emailbodyconsolidated');
        if (empty($bodytemplate)) {
            $bodytemplate = get_string('student_emailbodyconsolidated_default', 'local_course_reminder');
        }

        $courselist = '';
        $counter = 1;
        foreach ($enrollments as $enrollment) {
            $courselist .= "{$counter}. {$enrollment->coursename}\n";
            $counter++;
        }

        $username = !empty($enrollments) ? $enrollments[0]->employeename : fullname($studentuser);

        $replacements = [
            '{username}'   => $username,
            '{courselist}' => $courselist,
            '{days}'       => $studentdays,
            '{sitename}'   => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        $noreplyuser = core_user::get_noreply_user();

        return email_to_user(
            $studentuser,
            $noreplyuser,
            $subject,
            strip_tags($message),
            nl2br($message),
            '',
            true
        );
    }

    /**
     * Resolves the Processing Start Date setting to a Unix timestamp.
     *
     * The deadline-driven reminders apply this as a floor on the course end date, so that
     * switching them on does not sweep in every course that has ever ended. Returns 0 when
     * the setting is blank or malformed, which every timestamp satisfies.
     *
     * @return int Unix timestamp, or 0 when no floor is configured.
     */
    private function get_processing_start_timestamp(): int {
        $processstartstr = get_config('local_course_reminder', 'processing_start_date');

        if (empty($processstartstr)) {
            return 0;
        }

        $parseddate = \DateTime::createFromFormat('Y-m-d', $processstartstr);
        if ($parseddate && $parseddate->format('Y-m-d') === $processstartstr) {
            return (int) strtotime($processstartstr . ' 00:00:00 UTC');
        }

        mtrace('Warning: Processing Start Date "' . $processstartstr
            . '" is not a valid YYYY-MM-DD date. Course end date floor disabled.');

        return 0;
    }

    /**
     * Builds and runs the enrolment query shared by the expiry and overdue reminders.
     *
     * Only courses that carry an end date are considered. Completion state and the reminder
     * log are joined in rather than tested per row. The log join also matches on refdate, so
     * once an administrator changes a course end date the row stops matching and the reminder
     * re-arms for the new deadline.
     *
     * @param string $remindertype        Log reminder type, 'expiry' or 'overdue'.
     * @param string $typewhere           Extra WHERE conditions specific to the reminder type.
     * @param array  $typeparams          Named parameters referenced by $typewhere.
     * @param int[]  $excludedcategoryids Category IDs (including descendants) to exclude.
     * @return \moodle_recordset Enrolment rows still awaiting this reminder.
     */
    private function get_deadline_recordset(
        string $remindertype,
        string $typewhere,
        array $typeparams,
        array $excludedcategoryids
    ) {
        global $DB;

        $excludeclause = '';
        $excludeparams = [];
        if (!empty($excludedcategoryids)) {
            [$excludeclause, $excludeparams] = $DB->get_in_or_equal(
                $excludedcategoryids,
                SQL_PARAMS_NAMED,
                'exccat',
                false
            );
            $excludeclause = 'AND c.category ' . $excludeclause;
            mtrace('Excluding courses in ' . count($excludedcategoryids)
                . ' category IDs (selected + sub-categories).');
        }

        $sql = "SELECT ue.id, ue.userid, e.courseid, c.fullname AS coursename, c.enddate,
                       u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {course_categories} cat ON cat.id = c.category
                  JOIN {user} u ON u.id = ue.userid
             LEFT JOIN {course_completions} comp
                    ON comp.userid = ue.userid AND comp.course = e.courseid
                   AND comp.timecompleted > 0
             LEFT JOIN {local_course_reminder_log} rl
                    ON rl.userid = ue.userid AND rl.courseid = e.courseid
                   AND rl.remindertype = :rtype AND rl.refdate = c.enddate
                 WHERE c.enddate > 0
                   AND c.enddate >= :enddatefloor
                   AND c.enablecompletion = 1
                   AND ue.status = 0
                   AND e.status = 0
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND u.confirmed = 1
                   AND c.visible = 1
                   AND c.id != 1
                   AND cat.visible = 1
                   AND (c.startdate = 0 OR c.startdate <= :nowstart)
                   AND comp.id IS NULL
                   AND rl.id IS NULL
                   {$typewhere}
                   {$excludeclause}";

        $params = array_merge([
            'rtype'        => $remindertype,
            'enddatefloor' => $this->get_processing_start_timestamp(),
            'nowstart'     => time(),
        ], $typeparams, $excludeparams);

        return $DB->get_recordset_sql($sql, $params);
    }

    /**
     * Runs the course expiry reminder pipeline.
     *
     * Warns participants who have not completed a course that its end date is approaching.
     * Courses without an end date are never included.
     *
     * @param int   $days                Days before the course end date to send the warning.
     * @param int[] $excludedcategoryids Category IDs (including descendants) to exclude.
     * @return void
     */
    private function process_expiry_reminders(int $days, array $excludedcategoryids): void {
        $now           = time();
        $todaymidnight = strtotime('today midnight');

        // Compare against the end of the target day rather than its midnight, because course
        // end dates routinely carry a time of day. Without this a course ending at 17:00 would
        // give one day less notice than configured.
        $thresholdend = $todaymidnight + (($days + 1) * 86400);

        $typewhere = 'AND c.enddate > :expnow AND c.enddate < :thresholdend'
            . ' AND (ue.timeend = 0 OR ue.timeend > :timeendnow)';

        $enrollments = $this->get_deadline_recordset('expiry', $typewhere, [
            'expnow'       => $now,
            'thresholdend' => $thresholdend,
            'timeendnow'   => $now,
        ], $excludedcategoryids);

        $this->dispatch_deadline_reminders($enrollments, 'expiry', $days, false);
    }

    /**
     * Runs the course overdue reminder pipeline.
     *
     * Notifies participants who have not completed a course once its end date has passed,
     * copying the reporting manager when an address is available. Courses without an end
     * date are never included.
     *
     * @param int   $days                Days after the course end date to send the notice.
     * @param int[] $excludedcategoryids Category IDs (including descendants) to exclude.
     * @return void
     */
    private function process_overdue_reminders(int $days, array $excludedcategoryids): void {
        $todaymidnight = strtotime('today midnight');

        // Exclusion-based, matching the day counting used elsewhere in this plugin:
        // days=3 with an end date of 1 Apr fires on 4 Apr, not 3 Apr.
        $threshold = $todaymidnight - (($days - 1) * 86400);

        // Enrolment durations are commonly tied to the course end date, so the usual
        // "enrolment still running today" test would discard exactly the rows this pipeline
        // exists to find. Require only that the enrolment was still valid at the deadline.
        $typewhere = 'AND c.enddate < :threshold'
            . ' AND (ue.timeend = 0 OR ue.timeend > c.enddate)';

        $enrollments = $this->get_deadline_recordset('overdue', $typewhere, [
            'threshold' => $threshold,
        ], $excludedcategoryids);

        $this->dispatch_deadline_reminders($enrollments, 'overdue', $days, true);
    }

    /**
     * Sends one deadline reminder per enrolment row and records each successful send.
     *
     * @param \moodle_recordset $enrollments  Rows returned by get_deadline_recordset().
     * @param string            $remindertype 'expiry' or 'overdue'.
     * @param int               $days         Configured threshold, used in the templates.
     * @param bool              $ccmanager    Whether to copy the reporting manager.
     * @return void
     */
    private function dispatch_deadline_reminders($enrollments, string $remindertype, int $days, bool $ccmanager): void {
        global $DB;

        $processed = $emailssent = $skippedduplicate = $skippednouser = $failed = 0;
        $seen = [];

        foreach ($enrollments as $enrollment) {
            try {
                // A learner enrolled in one course by two methods yields two rows. The log
                // join cannot catch that, because it is evaluated once when the query runs.
                if (isset($seen[$enrollment->userid][$enrollment->courseid])) {
                    $skippedduplicate++;
                    continue;
                }
                $seen[$enrollment->userid][$enrollment->courseid] = true;
                $processed++;

                $user = core_user::get_user($enrollment->userid);
                if (!$user || $user->deleted || $user->suspended) {
                    $skippednouser++;
                    continue;
                }

                // A missing or unusable manager address must never stop the participant
                // being told, so this is resolved on a best-effort basis only.
                $manager = null;
                if ($ccmanager) {
                    $manager = $this->get_manager_data($enrollment->userid);
                }

                if (!$this->send_deadline_email($user, $enrollment, $remindertype, $days, $manager)) {
                    $failed++;
                    mtrace("Warning: Failed to send {$remindertype} reminder to {$enrollment->email}"
                        . " for course {$enrollment->coursename}");
                    continue;
                }

                // Re-read rather than trusting the join: a row may already exist for an
                // earlier end date, and the unique index spans only user, course and type.
                $logrecord = $DB->get_record('local_course_reminder_log', [
                    'userid'       => $enrollment->userid,
                    'courseid'     => $enrollment->courseid,
                    'remindertype' => $remindertype,
                ]);

                $this->upsert_log(
                    $enrollment->userid,
                    $enrollment->courseid,
                    $remindertype,
                    $logrecord,
                    (int) $enrollment->enddate
                );
                $emailssent++;
            } catch (\Exception $e) {
                mtrace("Error processing {$remindertype} reminder for enrollment {$enrollment->id}: "
                    . $e->getMessage());
            }
        }

        $enrollments->close();

        mtrace(ucfirst($remindertype) . ' reminder task completed.');
        mtrace("Total processed: {$processed}");
        mtrace("Emails sent: {$emailssent}");
        mtrace("Skipped (duplicate enrolment): {$skippedduplicate}");
        mtrace("Skipped (user unavailable): {$skippednouser}");
        mtrace("Failed sends: {$failed}");
    }

    /**
     * Builds and sends one expiry or overdue email.
     *
     * @param stdClass      $user         The participant's Moodle user object.
     * @param stdClass      $enrollment   Enrolment row including coursename and enddate.
     * @param string        $remindertype 'expiry' or 'overdue'.
     * @param int           $days         Configured threshold in days.
     * @param stdClass|null $manager      Manager data to copy in, or null for no copy.
     * @return bool True if the email was sent successfully.
     */
    private function send_deadline_email($user, $enrollment, string $remindertype, int $days, $manager) {
        global $DB;

        $sitename = $DB->get_field('config', 'value', ['name' => 'fullname']);
        $enddate  = (int) $enrollment->enddate;

        $prefix = ($remindertype === 'expiry') ? 'expiry' : 'overdue';

        $subjecttemplate = get_config('local_course_reminder', $prefix . '_emailsubject');
        if (empty($subjecttemplate)) {
            $subjecttemplate = get_string($prefix . '_emailsubject_default', 'local_course_reminder');
        }

        $bodytemplate = get_config('local_course_reminder', $prefix . '_emailbody');
        if (empty($bodytemplate)) {
            $bodytemplate = get_string($prefix . '_emailbody_default', 'local_course_reminder');
        }

        $todaymidnight   = strtotime('today midnight');
        $enddatemidnight = strtotime('midnight', $enddate);
        $daysremaining   = (int) (($enddatemidnight - $todaymidnight) / 86400);
        $daysoverdue     = (int) (($todaymidnight - $enddatemidnight) / 86400);

        $ccemail     = (!empty($manager) && !empty($manager->manager_email)) ? $manager->manager_email : '';
        $managername = (!empty($manager) && !empty($manager->manager_name)) ? $manager->manager_name : '';

        $replacements = [
            '{coursename}'    => $enrollment->coursename,
            '{username}'      => fullname($enrollment),
            '{managername}'   => $managername,
            '{enddate}'       => userdate($enddate, get_string('strftimedate', 'langconfig')),
            '{days}'          => $days,
            '{daysremaining}' => max(0, $daysremaining),
            '{daysoverdue}'   => max(0, $daysoverdue),
            '{sitename}'      => $sitename,
        ];

        $subject = str_replace(array_keys($replacements), array_values($replacements), $subjecttemplate);
        $message = str_replace(array_keys($replacements), array_values($replacements), $bodytemplate);

        return $this->send_email_with_cc($user, $subject, strip_tags($message), nl2br($message), $ccemail);
    }

    /**
     * Sends an email, optionally copying a second address on the same message.
     *
     * With no copy to add this simply defers to email_to_user(), which is the path every
     * pre-existing reminder in this plugin continues to use. A copy cannot be handled that
     * way: email_to_user() has no CC parameter, and a hand-written Cc header would not add
     * an SMTP envelope recipient, so the copy would never be delivered. The message is
     * therefore assembled directly, repeating the guards email_to_user() applies so that a
     * copied send is no less safe than a normal one. Delivery still goes through whichever
     * mailer the site has configured, because interception happens inside PHPMailer itself.
     *
     * @param stdClass $user        Recipient user object.
     * @param string   $subject     Message subject.
     * @param string   $messagetext Plain text body.
     * @param string   $messagehtml HTML body.
     * @param string   $ccemail     Address to copy, or empty for none.
     * @return bool True if the message was accepted for delivery.
     */
    private function send_email_with_cc($user, $subject, $messagetext, $messagehtml, $ccemail = '') {
        global $CFG;

        $noreplyuser = core_user::get_noreply_user();

        if (empty($ccemail) || !validate_email($ccemail)) {
            return (bool) email_to_user($user, $noreplyuser, $subject, $messagetext, $messagehtml, '', '', true);
        }

        if (empty($user) || empty($user->id) || empty($user->email) || !empty($user->deleted)) {
            return false;
        }

        if (!empty($CFG->noemailever)) {
            mtrace('Not sending email due to $CFG->noemailever config setting.');
            return true;
        }

        if (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) {
            return (bool) email_to_user($user, $noreplyuser, $subject, $messagetext, $messagehtml, '', '', true);
        }

        // Honour email diversion by redirecting both recipients, so that test and staging
        // sites exercise this path rather than silently skipping it.
        if (email_should_be_diverted($user->email)) {
            // Name the intended copy recipient in the subject. Both addresses collapse to the
            // same diverted mailbox, so without this a tester cannot tell a copied reminder
            // apart from an uncopied one.
            $subject = "[DIVERTED {$user->email} CC {$ccemail}] $subject";
            $user = clone($user);
            $user->email = $CFG->divertallemailsto;
            $ccemail = $CFG->divertallemailsto;
        }

        if (
            (isset($user->auth) && $user->auth === 'nologin')
                || (isset($user->suspended) && $user->suspended)
        ) {
            return true;
        }

        if (!validate_email($user->email)) {
            return false;
        }

        if (over_bounce_threshold($user)) {
            return false;
        }

        // The .invalid TLD is reserved for addresses that are known not to work.
        if (substr($user->email, -8) === '.invalid') {
            return true;
        }

        $mail = get_mailer();
        if (empty($mail)) {
            return (bool) email_to_user($user, $noreplyuser, $subject, $messagetext, $messagehtml, '', '', true);
        }

        $noreplydefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
        $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplydefault : $CFG->noreplyaddress;
        if (!validate_email($noreplyaddress)) {
            $noreplyaddress = $noreplydefault;
        }

        $sent = false;

        try {
            $mail->Sender   = $noreplyaddress;
            $mail->From     = $noreplyaddress;
            $mail->FromName = fullname($noreplyuser);
            $mail->Subject  = substr($subject, 0, 900);

            $mail->addAddress($user->email, fullname($user));

            // Adding the same address twice is rejected by the mailer, which would abort
            // the whole message. This happens whenever diversion is active.
            if (strcasecmp($ccemail, $user->email) !== 0) {
                $mail->addCC($ccemail);
            }

            if (!empty($messagehtml) && !empty($user->mailformat) && (int) $user->mailformat === 1) {
                $mail->isHTML(true);
                $mail->Encoding = 'quoted-printable';
                $mail->Body     = $messagehtml;
                $mail->AltBody  = "\n$messagetext\n";
            } else {
                $mail->isHTML(false);
                $mail->Body = "\n$messagetext\n";
            }

            $sent = $mail->send();
        } catch (\Exception $e) {
            mtrace('Warning: Could not build copied email for ' . $user->email . ': ' . $e->getMessage());
            $sent = false;
        }

        if ($sent) {
            return true;
        }

        // An unusable manager address must not cost the participant their reminder.
        mtrace('Warning: Sending with a copy to ' . $ccemail . ' failed. Retrying without the copy.');

        return (bool) email_to_user($user, $noreplyuser, $subject, $messagetext, $messagehtml, '', '', true);
    }
}
