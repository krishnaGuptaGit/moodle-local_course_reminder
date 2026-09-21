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
 * Language strings.
 *
 * @package    local_course_reminder
 * @copyright  2026 Krishna Gupta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['emailtype_consolidated'] = 'Consolidated Email';
$string['emailtype_individual'] = 'Individual Email';
$string['enable'] = 'Enable Plugin';
$string['enable_desc'] = 'Master switch to enable or disable all course reminder features';
$string['excluded_categoryids'] = 'Excluded Course Categories';
$string['excluded_categoryids_desc'] = 'Select course categories to exclude from all reminder notifications. Courses in the selected categories — and all their sub-categories — will never trigger manager or student reminder emails. Leave blank to exclude no categories.';
$string['expiry_days'] = 'Days Before Expiry';
$string['expiry_days_desc'] = 'Number of days before the course end date to send the expiry reminder. Example: if set to 7 and the course ends on 10 Oct, the reminder is sent on 3 Oct. Minimum 1.';
$string['expiry_emailbody'] = 'Expiry Reminder Email Body';
$string['expiry_emailbody_default'] = "Dear {username},\n\nThe following course is due to expire on {enddate}:\n\n{coursename}\n\nYou have {daysremaining} day(s) left to complete it. Please log in to the <a href=\"#\" target=\"_blank\">LMS</a> and complete the course before the deadline.\n\nIf you have already completed the course, please ignore this message.\n\nFor any access-related issues, you may contact the IT support team.\n\nRegards,\nLMS Administration Team";
$string['expiry_emailbody_desc'] = 'Available variables: {coursename}, {username}, {enddate}, {days}, {daysremaining}, {sitename}. {days} = configured threshold; {daysremaining} = actual days left until the course end date.';
$string['expiry_emailsubject'] = 'Expiry Reminder Email Subject';
$string['expiry_emailsubject_default'] = 'Course Expiring Soon: {coursename}';
$string['expiry_emailsubject_desc'] = 'Available variables: {coursename}, {username}, {enddate}, {days}, {daysremaining}, {sitename}';
$string['expiry_enable'] = 'Enable Expiry Reminders';
$string['expiry_enable_desc'] = 'Send a reminder to participants who have not completed a course, a set number of days before the course end date. Only courses that have an end date are considered. Each participant receives this reminder once per course deadline.';
$string['expiryoverduesettings'] = 'Course Expiry & Overdue Reminders';
$string['generalsettings'] = 'General Settings';
$string['manager_cycledays'] = 'Manager Reminder Cycle Days';
$string['manager_cycledays_desc'] = 'Number of days between repeat reminders to the manager after the first reminder has been sent. Set to 1 for daily reminders. Example: if set to 2 and the first reminder was sent on 4 Apr, the next reminder is sent on 6 Apr.';
$string['manager_days'] = 'Manager Reminder Days';
$string['manager_days_desc'] = 'Number of days after enrollment before sending the first escalation reminder to the manager. The enrollment day is excluded from the count. Example: if set to 3 and a learner enrolls on 1 Apr, the first reminder is sent on 4 Apr.';
$string['manager_emailbodyconsolidated'] = 'Consolidated Email Body';
$string['manager_emailbodyconsolidated_default'] = "Dear {managername},\n\nThe following employees have incomplete courses:\n\n{employeelist}\n\nPlease follow up with them to ensure they complete their training.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
$string['manager_emailbodyconsolidated_desc'] = 'Available variables: {managername}, {employeelist}, {sitename}';
$string['manager_emailbodyindividual'] = 'Individual Email Body';
$string['manager_emailbodyindividual_default'] = "Dear {managername},\n\nThis is a reminder that {username} has been enrolled in the course \"{coursename}\" for {days} days but has not yet completed it.\n\nPlease follow up with the learner to ensure they complete their training.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
$string['manager_emailbodyindividual_desc'] = 'Available variables: {coursename}, {username}, {managername}, {days}, {enrolleddays}, {sitename}. {days} = configured threshold; {enrolleddays} = actual days since enrollment.';
$string['manager_emailsettings'] = 'Manager Email Templates';
$string['manager_emailsubjectconsolidated'] = 'Consolidated Email Subject';
$string['manager_emailsubjectconsolidated_default'] = 'Course Escalation Reminder';
$string['manager_emailsubjectconsolidated_desc'] = 'Available variables: {managername}, {sitename}';
$string['manager_emailsubjectindividual'] = 'Individual Email Subject';
$string['manager_emailsubjectindividual_default'] = 'Course Escalation Reminder: {coursename}';
$string['manager_emailsubjectindividual_desc'] = 'Available variables: {coursename}, {username}, {managername}, {days}, {enrolleddays}, {sitename}';
$string['manager_emailtype'] = 'Email Type';
$string['manager_emailtype_desc'] = 'Choose whether to send individual emails or a consolidated email per manager';
$string['manager_enable'] = 'Enable Manager Escalation Reminders';
$string['manager_enable_desc'] = 'Send reminder emails to managers when their subordinates have not completed enrolled courses';
$string['managersettings'] = 'Manager Escalation Settings';
$string['overdue_days'] = 'Days After End Date';
$string['overdue_days_desc'] = 'Number of days after the course end date to send the overdue reminder. The end date day itself is not counted. Example: if set to 3 and the course ended on 1 Oct, the reminder is sent on 4 Oct. Minimum 1.';
$string['overdue_emailbody'] = 'Overdue Reminder Email Body';
$string['overdue_emailbody_default'] = "Dear {username},\n\nThe following course passed its completion deadline on {enddate} and is now overdue:\n\n{coursename}\n\nThis course is {daysoverdue} day(s) overdue. Please log in to the <a href=\"#\" target=\"_blank\">LMS</a> and complete it at the earliest.\n\nIf you have already completed the course, please ignore this message.\n\nFor any access-related issues, you may contact the IT support team.\n\nRegards,\nLMS Administration Team";
$string['overdue_emailbody_desc'] = 'Available variables: {coursename}, {username}, {managername}, {enddate}, {days}, {daysoverdue}, {sitename}. {days} = configured threshold; {daysoverdue} = actual days since the course end date.';
$string['overdue_emailsubject'] = 'Overdue Reminder Email Subject';
$string['overdue_emailsubject_default'] = 'Course Overdue: {coursename}';
$string['overdue_emailsubject_desc'] = 'Available variables: {coursename}, {username}, {managername}, {enddate}, {days}, {daysoverdue}, {sitename}';
$string['overdue_enable'] = 'Enable Overdue Reminders';
$string['overdue_enable_desc'] = 'Send a reminder to participants who have not completed a course, a set number of days after the course end date has passed. Only courses that have an end date are considered. The participant\'s reporting manager is copied on this email when a manager email address is available. Each participant receives this reminder once per course deadline.';
$string['pluginname'] = 'Course Escalation Reminder';
$string['privacy:metadata:local_course_reminder_log'] = 'Records when reminder emails were last sent to or about a user for each enrolled course.';
$string['privacy:metadata:local_course_reminder_log:courseid'] = 'The ID of the course the reminder relates to.';
$string['privacy:metadata:local_course_reminder_log:refdate'] = 'The course end date the reminder was sent for, used to avoid repeating expiry and overdue reminders for the same deadline.';
$string['privacy:metadata:local_course_reminder_log:remindertype'] = 'The type of reminder sent: student (direct to learner), manager (escalation to reporting manager), expiry (course nearing its end date) or overdue (course past its end date).';
$string['privacy:metadata:local_course_reminder_log:timesent'] = 'The Unix timestamp of when the last reminder email was successfully sent.';
$string['privacy:metadata:local_course_reminder_log:userid'] = 'The ID of the user the reminder relates to.';
$string['processing_start_date'] = 'Processing Start Date';
$string['processing_start_date_desc'] = 'Only enrolments created or started on or after this date will be processed. Leave blank to disable the guard and process all enrolments regardless of age.';
$string['processing_start_date_invalid'] = 'Invalid date. Please select a valid date using the date picker.';
$string['student_cycledays'] = 'Student Reminder Cycle Days';
$string['student_cycledays_desc'] = 'Number of days between repeat reminders to the student after the first reminder has been sent. Set to 1 for daily reminders. Example: if set to 2 and the first reminder was sent on 4 Apr, the next reminder is sent on 6 Apr.';
$string['student_days'] = 'Student Reminder Days';
$string['student_days_desc'] = 'Number of days after enrollment before sending the first reminder to the student. The enrollment day is excluded from the count. Example: if set to 3 and a student enrolls on 1 Apr, the first reminder is sent on 4 Apr.';
$string['student_emailbodyconsolidated'] = 'Consolidated Email Body';
$string['student_emailbodyconsolidated_default'] = "Dear {username},\n\nThe following courses require your attention:\n\n{courselist}\n\nPlease log in and complete your training at your earliest convenience.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
$string['student_emailbodyconsolidated_desc'] = 'Available variables: {username}, {courselist}, {days}, {sitename}';
$string['student_emailbodyindividual'] = 'Individual Email Body';
$string['student_emailbodyindividual_default'] = "Dear {username},\n\nThis is a reminder that you have been enrolled in the course \"{coursename}\" for {days} days but have not yet completed it.\n\nPlease log in and continue your training at your earliest convenience.\n\nThis is an automated message from {sitename}.\n\nBest regards,\nLearning Management System";
$string['student_emailbodyindividual_desc'] = 'Available variables: {coursename}, {username}, {days}, {enrolleddays}, {sitename}. {days} = configured threshold; {enrolleddays} = actual days since enrollment.';
$string['student_emailsettings'] = 'Student Email Templates';
$string['student_emailsubjectconsolidated'] = 'Consolidated Email Subject';
$string['student_emailsubjectconsolidated_default'] = 'Reminder: Complete Your Courses';
$string['student_emailsubjectconsolidated_desc'] = 'Available variables: {username}, {sitename}';
$string['student_emailsubjectindividual'] = 'Individual Email Subject';
$string['student_emailsubjectindividual_default'] = 'Reminder: Complete Your Course - {coursename}';
$string['student_emailsubjectindividual_desc'] = 'Available variables: {coursename}, {username}, {days}, {enrolleddays}, {sitename}';
$string['student_emailtype'] = 'Email Type';
$string['student_emailtype_desc'] = 'Choose whether to send one email per course (individual) or a single consolidated email per student';
$string['student_enable'] = 'Enable Student Reminders';
$string['student_enable_desc'] = 'Send reminder emails directly to students who have not completed their enrolled course';
$string['studentremindersettings'] = 'Student Reminder Settings';
$string['taskname'] = 'Send Course Escalation Reminder';
