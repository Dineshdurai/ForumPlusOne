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
 * Event observers used in forum.
 *
 * @package    mod_forumimproved
 * @copyright  2013 Rajesh Taneja <rajesh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for mod_forumimproved.
 */
class mod_forumimproved_observer {

    /**
     * Triggered via user_enrolment_deleted event.
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event) {
        global $DB;

        // NOTE: this has to be as fast as possible.
        // Get user enrolment info from event.
        $cp = (object)$event->other['userenrolment'];
        if ($cp->lastenrol) {
            $params = array('userid' => $cp->userid, 'courseid' => $cp->courseid);
            $forumselect = "IN (SELECT f.id FROM {forumimproved} f WHERE f.course = :courseid)";

            $DB->delete_records_select('forumimproved_digests', 'userid = :userid AND forum '.$forumselect, $params);
            $DB->delete_records_select('forumimproved_subscriptions', 'userid = :userid AND forum '.$forumselect, $params);
            $DB->delete_records_select('forumimproved_track_prefs', 'userid = :userid AND forumid '.$forumselect, $params);
            $DB->delete_records_select('forumimproved_read', 'userid = :userid AND forumid '.$forumselect, $params);
        }
    }

    /**
     * Observer for role_assigned event.
     *
     * @param \core\event\role_assigned $event
     * @return void
     */
    public static function role_assigned(\core\event\role_assigned $event) {
        global $CFG, $DB;

        $context = context::instance_by_id($event->contextid, MUST_EXIST);

        // If contextlevel is course then only subscribe user. Role assignment
        // at course level means user is enroled in course and can subscribe to forum.
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        // Forum lib required for the constant used below.
        require_once($CFG->dirroot . '/mod/forumimproved/lib.php');

        $userid = $event->relateduserid;
        $sql = "SELECT f.id, cm.id AS cmid
                  FROM {forumimproved} f
                  JOIN {course_modules} cm ON (cm.instance = f.id)
                  JOIN {modules} m ON (m.id = cm.module)
             LEFT JOIN {forumimproved_subscriptions} fs ON (fs.forum = f.id AND fs.userid = :userid)
                 WHERE f.course = :courseid
                   AND f.forcesubscribe = :initial
                   AND m.name = 'forumimproved'
                   AND fs.id IS NULL";
        $params = array('courseid' => $context->instanceid, 'userid' => $userid, 'initial' => FORUMIMPROVED_INITIALSUBSCRIBE);

        $forums = $DB->get_records_sql($sql, $params);
        foreach ($forums as $forum) {
            // If user doesn't have allowforcesubscribe capability then don't subscribe.
            $modcontext = context_module::instance($forum->cmid);
            if (has_capability('mod/forumimproved:allowforcesubscribe', $modcontext, $userid)) {
                forumimproved_subscribe($userid, $forum->id, $modcontext);
            }
        }
    }

    /**
     * Observer for \core\event\course_module_created event.
     *
     * @param \core\event\course_module_created $event
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event) {
        global $CFG;

        if ($event->other['modulename'] === 'forumimproved') {
            // Include the forum library to make use of the forumimproved_instance_created function.
            require_once($CFG->dirroot . '/mod/forumimproved/lib.php');

            $forum = $event->get_record_snapshot('forumimproved', $event->other['instanceid']);
            forumimproved_instance_created($event->get_context(), $forum);
        }
    }
}
