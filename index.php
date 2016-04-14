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
 * @package   mod_forumimproved
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @author Mark Nielsen
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/forumimproved/lib.php');
require_once($CFG->libdir . '/rsslib.php');

$id = optional_param('id', 0, PARAM_INT);                   // Course id
$subscribe = optional_param('subscribe', null, PARAM_INT);  // Subscribe/Unsubscribe all forums

$config = get_config('forumimproved');

$url = new moodle_url('/mod/forumimproved/index.php', array('id'=>$id));
if ($subscribe !== null) {
    require_sesskey();
    $url->param('subscribe', $subscribe);
}
$PAGE->set_url($url);

if ($id) {
    if (! $course = $DB->get_record('course', array('id' => $id))) {
        print_error('invalidcourseid');
    }
} else {
    $course = get_site();
}

require_course_login($course);
$PAGE->set_pagelayout('incourse');
$coursecontext = context_course::instance($course->id);


unset($SESSION->fromdiscussion);

$params = array(
    'context' => context_course::instance($course->id)
);
$event = \mod_forumimproved\event\course_module_instance_list_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->trigger();

$strforums       = get_string('forums', 'forumimproved');
$strforum        = get_string('forum', 'forumimproved');
$strdescription  = get_string('description');
$strdiscussions  = get_string('discussions', 'forumimproved');
$strsubscribed   = get_string('subscribed', 'forumimproved');
$strunreadposts  = get_string('unreadposts', 'forumimproved');
$strmarkallread  = get_string('markallread', 'forumimproved');
$strsubscribe    = get_string('subscribe', 'forumimproved');
$strunsubscribe  = get_string('unsubscribe', 'forumimproved');
$stryes          = get_string('yes');
$strno           = get_string('no');
$strrss          = get_string('rss');
$stremaildigest  = get_string('emaildigest');

$searchform = forumimproved_search_form($course);

// Retrieve the list of forum digest options for later.
$digestoptions = forumimproved_get_user_digest_options();
$digestoptions_selector = new single_select(new moodle_url('/mod/forumimproved/maildigest.php',
    array(
        'backtoindex' => 1,
    )),
    'maildigest',
    $digestoptions,
    null,
    '');
$digestoptions_selector->method = 'post';

// Start of the table for General Forums

$generaltable = new html_table();
$generaltable->head  = array ($strforum, $strdescription, $strdiscussions);
$generaltable->align = array ('left', 'left', 'center');

$generaltable->head[] = $strunreadposts;
$generaltable->align[] = 'center';

$subscribed_forums = forumimproved_get_subscribed_forums($course);

$can_subscribe = is_enrolled($coursecontext);
if ($can_subscribe) {
    $generaltable->head[] = $strsubscribed;
    $generaltable->align[] = 'center';

    $generaltable->head[] = $stremaildigest . ' ' . $OUTPUT->help_icon('emaildigesttype', 'mod_forumimproved');
    $generaltable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($config->enablerssfeeds) &&
                 $CFG->enablerssfeeds && $config->enablerssfeeds)) {
    $generaltable->head[] = $strrss;
    $generaltable->align[] = 'center';
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();

// Parse and organise all the forums.  Most forums are course modules but
// some special ones are not.  These get placed in the general forums
// category with the forums in section 0.

$forums = $DB->get_records_sql("
    SELECT f.*,
           d.maildigest
      FROM {forumimproved} f
 LEFT JOIN {forumimproved_digests} d ON d.forum = f.id AND d.userid = ?
     WHERE f.course = ?
    ", array($USER->id, $course->id));

$generalforums  = array();
$learningforums = array();
$modinfo = get_fast_modinfo($course);

foreach ($modinfo->get_instances_of('forumimproved') as $forumid=>$cm) {
    if (!$cm->uservisible or !isset($forums[$forumid])) {
        continue;
    }

    $forum = $forums[$forumid];

    if (!$context = context_module::instance($cm->id, IGNORE_MISSING)) {
        continue;   // Shouldn't happen
    }

    if (!has_capability('mod/forumimproved:viewdiscussion', $context)) {
        continue;
    }

    // fill two type array - order in modinfo is the same as in course
    if ($forum->type == 'news' or $forum->type == 'social') {
        $generalforums[$forum->id] = $forum;

    } else if ($course->id == SITEID or empty($cm->sectionnum)) {
        $generalforums[$forum->id] = $forum;

    } else {
        $learningforums[$forum->id] = $forum;
    }
}

/// Do course wide subscribe/unsubscribe
if (!is_null($subscribe) and !isguestuser()) {
    foreach ($modinfo->get_instances_of('forumimproved') as $forumid=>$cm) {
        $forum = $forums[$forumid];
        $modcontext = context_module::instance($cm->id);
        $cansub = false;

        if (has_capability('mod/forumimproved:viewdiscussion', $modcontext)) {
            $cansub = true;
        }
        if ($cansub && $cm->visible == 0 &&
            !has_capability('mod/forumimproved:managesubscriptions', $modcontext))
        {
            $cansub = false;
        }
        if (!forumimproved_is_forcesubscribed($forum)) {
            $subscribed = forumimproved_is_subscribed($USER->id, $forum);
            if ((has_capability('moodle/course:manageactivities', $coursecontext, $USER->id) || $forum->forcesubscribe != FORUMIMPROVED_DISALLOWSUBSCRIBE) && $subscribe && !$subscribed && $cansub) {
                forumimproved_subscribe($USER->id, $forumid, $modcontext);
            } else if (!$subscribe && $subscribed) {
                forumimproved_unsubscribe($USER->id, $forumid, $modcontext);
            }
        }
    }
    $returnto = forumimproved_go_back_to("index.php?id=$course->id");
    $shortname = format_string($course->shortname, true, array('context' => context_course::instance($course->id)));
    if ($subscribe) {
        redirect($returnto, get_string('nowallsubscribed', 'forumimproved', $shortname), 1);
    } else {
        redirect($returnto, get_string('nowallunsubscribed', 'forumimproved', $shortname), 1);
    }
}

/// First, let's process the general forums and build up a display

if ($generalforums) {
    foreach ($generalforums as $forum) {
        $cm      = $modinfo->instances['forumimproved'][$forum->id];
        $context = context_module::instance($cm->id);

        $count = forumimproved_count_discussions($forum, $cm, $course);
        if ($unread = forumimproved_count_forum_unread_posts($cm, $course)) {
            $unreadlink = '<span class="unread"><a href="view.php?f='.$forum->id.'">'.$unread.'</a>';
         } else {
             $unreadlink = '<span class="read">0</span>';
         }
        $forum->intro = shorten_text(format_module_intro('forumimproved', $forum, $cm->id), $config->shortpost);
        $forumname = format_string($forum->name, true);

        if ($cm->visible) {
            $style = '';
        } else {
            $style = 'class="dimmed"';
        }
        $forumlink = "<a href=\"view.php?f=$forum->id\" $style>".format_string($forum->name,true)."</a>";
        $discussionlink = "<a href=\"view.php?f=$forum->id\" $style>".$count."</a>";

        $row = array ($forumlink, $forum->intro, $discussionlink, $unreadlink);

        if ($can_subscribe) {
            $row[] = forumimproved_get_subscribe_link($forum, $context, array('subscribed' => $stryes,
                    'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                    'cantsubscribe' => '-'), false, false, true);

            $digestoptions_selector->url->param('id', $forum->id);
            if ($forum->maildigest === null) {
                $digestoptions_selector->selected = -1;
            } else {
                $digestoptions_selector->selected = $forum->maildigest;
            }
            $row[] = $OUTPUT->render($digestoptions_selector);
        }

        //If this forum has RSS activated, calculate it
        if ($show_rss) {
            if ($forum->rsstype and $forum->rssarticles) {
                //Calculate the tooltip text
                if ($forum->rsstype == 1) {
                    $tooltiptext = get_string('rsssubscriberssdiscussions', 'forumimproved');
                } else {
                    $tooltiptext = get_string('rsssubscriberssposts', 'forumimproved');
                }

                if (!isloggedin() && $course->id == SITEID) {
                    $userid = guest_user()->id;
                } else {
                    $userid = $USER->id;
                }
                //Get html code for RSS link
                $row[] = rss_get_link($context->id, $userid, 'mod_forumimproved', $forum->id, $tooltiptext);
            } else {
                $row[] = '&nbsp;';
            }
        }

        $generaltable->data[] = $row;
    }
}


// Start of the table for Learning Forums
$learningtable = new html_table();
$learningtable->head  = array ($strforum, $strdescription, $strdiscussions);
$learningtable->align = array ('left', 'left', 'center');

$learningtable->head[] = $strunreadposts;
$learningtable->align[] = 'center';

if ($can_subscribe) {
    $learningtable->head[] = $strsubscribed;
    $learningtable->align[] = 'center';

    $learningtable->head[] = $stremaildigest . ' ' . $OUTPUT->help_icon('emaildigesttype', 'mod_forumimproved');
    $learningtable->align[] = 'center';
}

if ($show_rss = (($can_subscribe || $course->id == SITEID) &&
                 isset($CFG->enablerssfeeds) && isset($config->enablerssfeeds) &&
                 $CFG->enablerssfeeds && $config->enablerssfeeds)) {
    $learningtable->head[] = $strrss;
    $learningtable->align[] = 'center';
}

/// Now let's process the learning forums

if ($course->id != SITEID) {    // Only real courses have learning forums
    // 'format_.'$course->format only applicable when not SITEID (format_site is not a format)
    $strsectionname  = get_string('sectionname', 'format_'.$course->format);
    // Add extra field for section number, at the front
    array_unshift($learningtable->head, $strsectionname);
    array_unshift($learningtable->align, 'center');


    if ($learningforums) {
        $currentsection = '';
            foreach ($learningforums as $forum) {
            $cm      = $modinfo->instances['forumimproved'][$forum->id];
            $context = context_module::instance($cm->id);

            $count = forumimproved_count_discussions($forum, $cm, $course);
            if ($unread = forumimproved_count_forum_unread_posts($cm, $course)) {
                $unreadlink = '<span class="unread"><a href="view.php?f='.$forum->id.'">'.$unread.'</a>';
             } else {
                 $unreadlink = '<span class="read">0</span>';
             }

            $forum->intro = shorten_text(format_module_intro('forumimproved', $forum, $cm->id), $config->shortpost);

            if ($cm->sectionnum != $currentsection) {
                $printsection = get_section_name($course, $cm->sectionnum);
                if ($currentsection) {
                    $learningtable->data[] = 'hr';
                }
                $currentsection = $cm->sectionnum;
            } else {
                $printsection = '';
            }

            $forumname = format_string($forum->name,true);

            if ($cm->visible) {
                $style = '';
            } else {
                $style = 'class="dimmed"';
            }
            $forumlink = "<a href=\"view.php?f=$forum->id\" $style>".format_string($forum->name,true)."</a>";
            $discussionlink = "<a href=\"view.php?f=$forum->id\" $style>".$count."</a>";

            $row = array ($printsection, $forumlink, $forum->intro, $discussionlink, $unreadlink);

            if ($can_subscribe) {
                $row[] = forumimproved_get_subscribe_link($forum, $context, array('subscribed' => $stryes,
                    'unsubscribed' => $strno, 'forcesubscribed' => $stryes,
                    'cantsubscribe' => '-'), false, false, true, $subscribed_forums);

                $digestoptions_selector->url->param('id', $forum->id);
                if ($forum->maildigest === null) {
                    $digestoptions_selector->selected = -1;
                } else {
                    $digestoptions_selector->selected = $forum->maildigest;
                }
                $row[] = $OUTPUT->render($digestoptions_selector);
            }

            //If this forum has RSS activated, calculate it
            if ($show_rss) {
                if ($forum->rsstype and $forum->rssarticles) {
                    //Calculate the tolltip text
                    if ($forum->rsstype == 1) {
                        $tooltiptext = get_string('rsssubscriberssdiscussions', 'forumimproved');
                    } else {
                        $tooltiptext = get_string('rsssubscriberssposts', 'forumimproved');
                    }
                    //Get html code for RSS link
                    $row[] = rss_get_link($context->id, $USER->id, 'mod_forumimproved', $forum->id, $tooltiptext);
                } else {
                    $row[] = '&nbsp;';
                }
            }

            $learningtable->data[] = $row;
        }
    }
}


/// Output the page
$PAGE->navbar->add($strforums);
$PAGE->set_title("$course->shortname: $strforums");
$PAGE->set_heading($course->fullname);
$PAGE->set_button($searchform);
echo $OUTPUT->header();

if (!isguestuser() && isloggedin()) {
    echo $OUTPUT->box_start('subscription');
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/forumimproved/index.php', array('id'=>$course->id, 'subscribe'=>1, 'sesskey'=>sesskey())),
            get_string('allsubscribe', 'forumimproved')),
        array('class'=>'helplink'));
    echo html_writer::tag('div',
        html_writer::link(new moodle_url('/mod/forumimproved/index.php', array('id'=>$course->id, 'subscribe'=>0, 'sesskey'=>sesskey())),
            get_string('allunsubscribe', 'forumimproved')),
        array('class'=>'helplink'));
    echo $OUTPUT->box_end();
    echo $OUTPUT->box('&nbsp;', 'clearer');
}

if ($generalforums) {
    echo $OUTPUT->heading(get_string('generalforums', 'forumimproved'), 2);
    echo html_writer::table($generaltable);
}

if ($learningforums) {
    echo $OUTPUT->heading(get_string('learningforums', 'forumimproved'), 2);
    echo html_writer::table($learningtable);
}

echo $OUTPUT->footer();

