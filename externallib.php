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
 * External forum API
 *
 * @package    mod_forumplusone
 * @copyright  2012 Mark Nelson <markn@moodle.com>
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");

class mod_forumplusone_external extends external_api {

    /**
     * Describes the parameters for get_forum.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_forums_by_courses_parameters() {
        return new external_function_parameters (
            array(
                'courseids' => new external_multiple_structure(new external_value(PARAM_INT, 'course ID',
                        '', VALUE_REQUIRED, '', NULL_NOT_ALLOWED), 'Array of Course IDs', VALUE_DEFAULT, array()),
            )
        );
    }

    /**
     * Returns a list of forums in a provided list of courses,
     * if no list is provided all forums that the user can view
     * will be returned.
     *
     * @param array $courseids the course ids
     * @return array the forum details
     * @since Moodle 2.5
     */
    public static function get_forums_by_courses($courseids = array()) {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . "/mod/forumplusone/lib.php");

        $params = self::validate_parameters(self::get_forums_by_courses_parameters(), array('courseids' => $courseids));

        if (empty($params['courseids'])) {
            // Get all the courses the user can view.
            $courseids = array_keys(enrol_get_my_courses());
        } else {
            $courseids = $params['courseids'];
        }

        // Array to store the forums to return.
        $arrforums = array();

        // Ensure there are courseids to loop through.
        if (!empty($courseids)) {
            // Go through the courseids and return the forums.
            foreach ($courseids as $cid) {
                // Get the course context.
                $context = context_course::instance($cid);
                // Check the user can function in this context.
                self::validate_context($context);
                // Get the forums in this course.
                if ($forums = $DB->get_records('forumplusone', array('course' => $cid))) {
                    // Get the modinfo for the course.
                    $modinfo = get_fast_modinfo($cid);
                    // Get the forum instances.
                    $foruminstances = $modinfo->get_instances_of('forumplusone');
                    // Loop through the forums returned by modinfo.
                    foreach ($foruminstances as $forumid => $cm) {
                        // If it is not visible or present in the forums get_records call, continue.
                        if (!$cm->uservisible || !isset($forums[$forumid])) {
                            continue;
                        }
                        // Set the forum object.
                        $forum = $forums[$forumid];
                        // Get the module context.
                        $context = context_module::instance($cm->id);
                        // Check they have the view forum capability.
                        require_capability('mod/forumplusone:viewdiscussion', $context);
                        // Format the intro before being returning using the format setting.
                        list($forum->intro, $forum->introformat) = external_format_text($forum->intro, $forum->introformat,
                            $context->id, 'mod_forumplusone', 'intro', 0);
                        // Add the course module id to the object, this information is useful.
                        $forum->cmid = $cm->id;
                        // Add the forum to the array to return.
                        $arrforums[$forum->id] = (array) $forum;
                    }
                }
            }
        }

        return $arrforums;
    }

    /**
     * Describes the get_forum return value.
     *
     * @return external_single_structure
     * @since Moodle 2.5
     */
     public static function get_forums_by_courses_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'course' => new external_value(PARAM_TEXT, 'Course id'),
                    'type' => new external_value(PARAM_TEXT, 'The forum type'),
                    'name' => new external_value(PARAM_TEXT, 'Forum name'),
                    'intro' => new external_value(PARAM_RAW, 'The forum intro'),
                    'introformat' => new external_format_value('intro'),
                    'assessed' => new external_value(PARAM_INT, 'Aggregate type'),
                    'assesstimestart' => new external_value(PARAM_INT, 'Assess start time'),
                    'assesstimefinish' => new external_value(PARAM_INT, 'Assess finish time'),
                    'scale' => new external_value(PARAM_INT, 'Scale'),
                    'maxbytes' => new external_value(PARAM_INT, 'Maximum attachment size'),
                    'maxattachments' => new external_value(PARAM_INT, 'Maximum number of attachments'),
                    'forcesubscribe' => new external_value(PARAM_INT, 'Force users to subscribe'),
                    'rsstype' => new external_value(PARAM_INT, 'RSS feed for this activity'),
                    'rssarticles' => new external_value(PARAM_INT, 'Number of RSS recent articles'),
                    'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    'warnafter' => new external_value(PARAM_INT, 'Post threshold for warning'),
                    'blockafter' => new external_value(PARAM_INT, 'Post threshold for blocking'),
                    'blockperiod' => new external_value(PARAM_INT, 'Time period for blocking'),
                    'completiondiscussions' => new external_value(PARAM_INT, 'Student must create discussions'),
                    'completionreplies' => new external_value(PARAM_INT, 'Student must post replies'),
                    'completionposts' => new external_value(PARAM_INT, 'Student must post discussions or replies'),
                    'cmid' => new external_value(PARAM_INT, 'Course module id')
                ), 'forum'
            )
        );
    }

    /**
     * Describes the parameters for get_forum_discussions.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.5
     */
    public static function get_forum_discussions_parameters() {
        return new external_function_parameters (
            array(
                'forumids' => new external_multiple_structure(new external_value(PARAM_INT, 'forum ID',
                        '', VALUE_REQUIRED, '', NULL_NOT_ALLOWED), 'Array of Forum IDs', VALUE_REQUIRED),
                'limitfrom' => new external_value(PARAM_INT, 'limit from', VALUE_DEFAULT, 0),
                'limitnum' => new external_value(PARAM_INT, 'limit number', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Returns a list of forum discussions as well as a summary of the discussion
     * in a provided list of forums.
     *
     * @param array $forumids the forum ids
     * @param int $limitfrom limit from SQL data
     * @param int $limitnum limit number SQL data
     *
     * @return array the forum discussion details
     * @since Moodle 2.5
     */
    public static function get_forum_discussions($forumids, $limitfrom = 0, $limitnum = 0) {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . "/mod/forumplusone/lib.php");

        // Validate the parameter.
        $params = self::validate_parameters(self::get_forum_discussions_parameters(),
            array(
                'forumids'  => $forumids,
                'limitfrom' => $limitfrom,
                'limitnum'  => $limitnum,
            ));
        $forumids  = $params['forumids'];
        $limitfrom = $params['limitfrom'];
        $limitnum  = $params['limitnum'];

        // Array to store the forum discussions to return.
        $arrdiscussions = array();
        // Keep track of the users we have looked up in the DB.
        $arrusers = array();

        // Loop through them.
        foreach ($forumids as $id) {
            // Get the forum object.
            $forum = $DB->get_record('forumplusone', array('id' => $id), '*', MUST_EXIST);

            $course = get_course($forum->course);

            $modinfo = get_fast_modinfo($course);
            $forums  = $modinfo->get_instances_of('forumplusone');
            $cm = $forums[$forum->id];

            // Get the module context.
            $modcontext = context_module::instance($cm->id);

            // Validate the context.
            self::validate_context($modcontext);

            require_capability('mod/forumplusone:viewdiscussion', $modcontext);

            // Get the discussions for this forum.
            $params = array();
            $groupselect = "";
            $groupmode = groups_get_activity_groupmode($cm, $course);

            if ($groupmode and $groupmode != VISIBLEGROUPS and !has_capability('moodle/site:accessallgroups', $modcontext)) {
                // Get all the discussions from all the groups this user belongs to.
                $usergroups = groups_get_user_groups($course->id);
                if (!empty($usergroups['0'])) {
                    list($sql, $params) = $DB->get_in_or_equal($usergroups['0']);
                    $groupselect = "AND (groupid $sql OR groupid = -1)";
                }

                array_unshift($params, $id);
                $select = "forum = ? $groupselect";

                if ($discussions = $DB->get_records_select('forumplusone_discussions', $select, $params, 'timemodified DESC', '*',
                    $limitfrom, $limitnum)) {

                    // Check if they can view full names.
                    $canviewfullname = has_capability('moodle/site:viewfullnames', $modcontext);
                    // Get the unreads array, this takes a forum id and returns data for all discussions.
                    $unreads = array();
                    if ($cantrack = forumplusone_tp_can_track_forums($forum)) {
                        if ($forumtracked = forumplusone_tp_is_tracked($forum)) {
                            $unreads = forumplusone_get_discussions_unread($cm);
                        }
                    }
                    // The forum function returns the replies for all the discussions in a given forum.
                    $replies = forumplusone_count_discussion_replies($id);
                    foreach ($discussions as $discussion) {
                        // This function checks capabilities, timed discussions, groups and qanda forums posting.
                        if (!forumplusone_user_can_see_discussion($forum, $discussion, $modcontext)) {
                            continue;
                        }
                        $usernamefields = user_picture::fields();
                        // If we don't have the users details then perform DB call.
                        if (empty($arrusers[$discussion->userid])) {
                            $arrusers[$discussion->userid] = $DB->get_record('user', array('id' => $discussion->userid),
                                    $usernamefields, MUST_EXIST);
                        }
                        // Create object to return.
                        $return = new stdClass();
                        $return->id = (int) $discussion->discussion;
                        $return->course = $cm->course;
                        $return->forum = $forum->id;
                        $return->name = $discussion->name;
                        $return->userid = $discussion->userid;
                        $return->groupid = $discussion->groupid;
                        $return->assessed = $discussion->assessed;
                        $return->timemodified = (int) $discussion->timemodified;
                        $return->usermodified = $discussion->usermodified;
                        $return->timestart = $discussion->timestart;
                        $return->timeend = $discussion->timeend;
                        $return->firstpost = (int) $discussion->id;
                        $return->firstuserfullname = fullname($arrusers[$discussion->userid], $canviewfullname);
                        $return->firstuserimagealt = $arrusers[$discussion->userid]->imagealt;
                        $return->firstuserpicture = $arrusers[$discussion->userid]->picture;
                        $return->firstuseremail = $arrusers[$discussion->userid]->email;
                        $return->numunread = '';
                        $return->numunread = (int) $discussion->unread;

                        // Check if there are any replies to this discussion.
                        if (!is_null($discussion->replies)) {
                            $return->numreplies = (int) $discussion->replies;
                            $return->lastpost = (int) $discussion->lastpostid;
                        } else { // No replies, so the last post will be the first post.
                            $return->numreplies = 0;
                            $return->lastpost = (int) $discussion->firstpost;
                        }
                        // Get the last post as well as the user who made it.
                        $lastpost = $DB->get_record('forumplusone_posts', array('id' => $return->lastpost), '*', MUST_EXIST);
                        if (empty($arrusers[$lastpost->userid])) {
                            $arrusers[$lastpost->userid] = $DB->get_record('user', array('id' => $lastpost->userid),
                                    $usernamefields, MUST_EXIST);
                        }
                        $return->lastuserid = $lastpost->userid;
                        $return->lastuserfullname = fullname($arrusers[$lastpost->userid], $canviewfullname);
                        $return->lastuserimagealt = $arrusers[$lastpost->userid]->imagealt;
                        $return->lastuserpicture = $arrusers[$lastpost->userid]->picture;
                        $return->lastuseremail = $arrusers[$lastpost->userid]->email;
                        // Add the discussion statistics to the array to return.
                        $arrdiscussions[$return->id] = (array) $return;
                    }
                    $discussions->close();
                }
            }
        }

        return $arrdiscussions;
    }

    /**
     * Describes the get_forum_discussions return value.
     *
     * @return external_single_structure
     * @since Moodle 2.5
     */
     public static function get_forum_discussions_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Forum id'),
                    'course' => new external_value(PARAM_INT, 'Course id'),
                    'forum' => new external_value(PARAM_INT, 'The forum id'),
                    'name' => new external_value(PARAM_TEXT, 'Discussion name'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'groupid' => new external_value(PARAM_INT, 'Group id'),
                    'assessed' => new external_value(PARAM_INT, 'Is this assessed?'),
                    'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    'usermodified' => new external_value(PARAM_INT, 'The id of the user who last modified'),
                    'timestart' => new external_value(PARAM_INT, 'Time discussion can start'),
                    'timeend' => new external_value(PARAM_INT, 'Time discussion ends'),
                    'firstpost' => new external_value(PARAM_INT, 'The first post in the discussion'),
                    'firstuserfullname' => new external_value(PARAM_TEXT, 'The discussion creators fullname'),
                    'firstuserimagealt' => new external_value(PARAM_TEXT, 'The discussion creators image alt'),
                    'firstuserpicture' => new external_value(PARAM_INT, 'The discussion creators profile picture'),
                    'firstuseremail' => new external_value(PARAM_TEXT, 'The discussion creators email'),
                    'numreplies' => new external_value(PARAM_TEXT, 'The number of replies in the discussion'),
                    'numunread' => new external_value(PARAM_TEXT, 'The number of unread posts, blank if this value is
                        not available due to forum settings.'),
                    'lastpost' => new external_value(PARAM_INT, 'The id of the last post in the discussion'),
                    'lastuserid' => new external_value(PARAM_INT, 'The id of the user who made the last post'),
                    'lastuserfullname' => new external_value(PARAM_TEXT, 'The last person to posts fullname'),
                    'lastuserimagealt' => new external_value(PARAM_TEXT, 'The last person to posts image alt'),
                    'lastuserpicture' => new external_value(PARAM_INT, 'The last person to posts profile picture'),
                    'lastuseremail' => new external_value(PARAM_TEXT, 'The last person to posts email'),
                ), 'discussion'
            )
        );
    }

    /**
     * Describes the parameters for get_forum_discussion_posts.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.7
     */
    public static function get_forum_discussion_posts_parameters() {
        return new external_function_parameters (
            array(
                'discussionid' => new external_value(PARAM_INT, 'discussion ID', VALUE_REQUIRED),
            )
        );
    }

    /**
     * Returns a list of forum posts for a discussion
     *
     * @param int $discussionid the post ids
     *
     * @return array the forum post details
     * @since Moodle 2.7
     */
    public static function get_forum_discussion_posts($discussionid) {
        global $CFG, $DB, $USER;

        $warnings = array();

        // Validate the parameter.
        $params = self::validate_parameters(
            self::get_forum_discussion_posts_parameters(),
            array('discussionid' => $discussionid)
        );

        // Compact/extract functions are not recommended.
        $discussionid   = $params['discussionid'];

        $discussion = $DB->get_record('forumplusone_discussions', array('id' => $discussionid), '*', MUST_EXIST);
        $forum = $DB->get_record('forumplusone', array('id' => $discussion->forum), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $forum->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forumplusone', $forum->id, $course->id, false, MUST_EXIST);

        // Validate the module context. It checks everything that affects the module visibility (including groupings, etc..).
        $modcontext = context_module::instance($cm->id);
        self::validate_context($modcontext);

        // This require must be here, see mod/forumplusone/discuss.php.
        require_once($CFG->dirroot . "/mod/forumplusone/lib.php");

        // Check they have the view forum capability.
        require_capability('mod/forumplusone:viewdiscussion', $modcontext, null, true, 'noviewdiscussionspermission', 'forum');

        if (! $post = forumplusone_get_post_full($discussion->firstpost)) {
            throw new moodle_exception('notexists', 'forumplusone');
        }

        // This function check groups, qanda, timed discussions, etc.
        if (!forumplusone_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            throw new moodle_exception('noviewdiscussionspermission', 'forumplusone');
        }

        $canviewfullname = has_capability('moodle/site:viewfullnames', $modcontext);

        // We will add this field in the response.
        $canreply = forumplusone_user_can_post($forum, $discussion, $USER, $cm, $course, $modcontext);

        $posts = forumplusone_get_all_discussion_posts($discussion->id);

        foreach ($posts as $pid => $post) {

            if (!forumplusone_user_can_see_post($forum, $discussion, $post, null, $cm)) {
                $warning = array();
                $warning['item'] = 'post';
                $warning['itemid'] = $post->id;
                $warning['warningcode'] = '1';
                $warning['message'] = 'You can\'t see this post';
                $warnings[] = $warning;
                continue;
            }

            // Function forumplusone_get_all_discussion_posts adds postread field.
            // Note that the value returned can be a boolean or an integer. The WS expects a boolean.
            if (empty($post->postread)) {
                $posts[$pid]->postread = false;
            } else {
                $posts[$pid]->postread = true;
            }

            $posts[$pid]->canreply = $canreply;
            if (!empty($posts[$pid]->children)) {
                $posts[$pid]->children = array_keys($posts[$pid]->children);
            } else {
                $posts[$pid]->children = array();
            }

            $user = new stdclass();
            $user = username_load_fields_from_object($user, $post);
            $posts[$pid]->userfullname = fullname($user, $canviewfullname);

            $posts[$pid] = (array) $post;
        }

        $result = array();
        $result['posts'] = $posts;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_forum_discussion_posts return value.
     *
     * @return external_single_structure
     * @since Moodle 2.7
     */
    public static function get_forum_discussion_posts_returns() {
        return new external_single_structure(
            array(
                'posts' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'id' => new external_value(PARAM_INT, 'Post id'),
                                'discussion' => new external_value(PARAM_INT, 'Discussion id'),
                                'parent' => new external_value(PARAM_INT, 'Parent id'),
                                'userid' => new external_value(PARAM_INT, 'User id'),
                                'created' => new external_value(PARAM_INT, 'Creation time'),
                                'modified' => new external_value(PARAM_INT, 'Time modified'),
                                'mailed' => new external_value(PARAM_INT, 'Mailed?'),
                                'message' => new external_value(PARAM_RAW, 'The post message'),
                                'messageformat' => new external_value(PARAM_INT, 'The post message format'),
                                'messagetrust' => new external_value(PARAM_INT, 'Can we trust?'),
                                'attachment' => new external_value(PARAM_RAW, 'Attachments'),
                                'totalscore' => new external_value(PARAM_INT, 'The post message total score'),
                                'mailnow' => new external_value(PARAM_INT, 'Mail now?'),
                                'children' => new external_multiple_structure(new external_value(PARAM_INT, 'children post id')),
                                'canreply' => new external_value(PARAM_BOOL, 'The user can reply to posts?'),
                                'postread' => new external_value(PARAM_BOOL, 'The post was read'),
                                'userfullname' => new external_value(PARAM_TEXT, 'Post author full name')
                            ), 'post'
                        )
                    ),
                'warnings' => new external_warnings()
            )
        );
    }

}
