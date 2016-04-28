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
 * This file contains a custom renderer class used by the forum module.
 *
 * @package   mod_forumimproved
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @author Mark Nielsen
 */

require_once(__DIR__.'/lib/discussion/subscribe.php');

/**
 * A custom renderer class that extends the plugin_renderer_base and
 * is used by the forum module.
 *
 * @package   mod_forumimproved
 * @copyright 2009 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @author Mark Nielsen
 **/
class mod_forumimproved_renderer extends plugin_renderer_base {

    /**
     * @param $course
     * @param $cm
     * @param $forum
     * @param context_module $context
     * @author Mark Nielsen
     */
    public function view($course, $cm, $forum, context_module $context) {
        global $USER, $DB, $OUTPUT;

        require_once(__DIR__.'/lib/discussion/sort.php');

        $config = get_config('forumimproved');
        $mode    = optional_param('mode', 0, PARAM_INT); // Display mode (for single forum)
        $page    = optional_param('page', 0, PARAM_INT); // which page to show
        $forumicon = "<img src='".$OUTPUT->pix_url('icon', 'forumimproved')."' alt='' class='iconlarge activityicon'/> ";
        echo '<div id="forumimproved-header"><h2>'.$forumicon.format_string($forum->name).'</h2>';
        if (!empty($forum->intro)) {
            echo '<div class="forumimproved_introduction">'.format_module_intro('forumimproved', $forum, $cm->id).'</div>';
        }
        echo "</div>";

        // Update activity group mode changes here.
        groups_get_activity_group($cm, true);

        $dsort = forumimproved_lib_discussion_sort::get_from_session($forum, $context);
        $dsort->set_key(optional_param('dsortkey', $dsort->get_key(), PARAM_ALPHA));
        forumimproved_lib_discussion_sort::set_to_session($dsort);


        if (!empty($forum->blockafter) && !empty($forum->blockperiod)) {
            $a = new stdClass();
            $a->blockafter = $forum->blockafter;
            $a->blockperiod = get_string('secondstotime'.$forum->blockperiod);
            echo $OUTPUT->notification(get_string('thisforumisthrottled', 'forumimproved', $a));
        }

        if ($forum->type == 'qanda' && !has_capability('moodle/course:manageactivities', $context)) {
            echo $OUTPUT->notification(get_string('qandanotify','forumimproved'));
        }

        switch ($forum->type) {
            case 'eachuser':
                if (forumimproved_user_can_post_discussion($forum, null, -1, $cm)) {
                    echo '<p class="mdl-align">';
                    print_string("allowsdiscussions", "forumimproved");
                    echo '</p>';
                }
            // Fall through to following cases.
            case 'blog':
            default:
                forumimproved_print_latest_discussions($course, $forum, -1, $dsort->get_sort_sql(), -1, -1, $page, $config->manydiscussions, $cm);
                break;
        }
    }

    /**
     * Render all discussions view, including add discussion button, etc...
     *
     * @param stdClass $forum - forum row
     * @return string
     */
    public function render_discussionsview($forum) {
        global $CFG, $DB, $PAGE, $SESSION;

        ob_start(); // YAK! todo, fix this rubbish.

        require_once($CFG->dirroot.'/mod/forumimproved/lib.php');
        require_once($CFG->libdir.'/completionlib.php');
        require_once($CFG->libdir.'/accesslib.php');

        $output = '';

        $modinfo = get_fast_modinfo($forum->course);
        $forums = $modinfo->get_instances_of('forumimproved');
        if (!isset($forums[$forum->id])) {
            print_error('invalidcoursemodule');
        }
        $cm = $forums[$forum->id];

        $id          = $cm->id;      // Forum instance id (id in course modules table)
        $f           = $forum->id;        // Forum ID

        $config = get_config('forumimproved');

        if ($id) {
            if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
                print_error('coursemisconf');
            }
        } else if ($f) {
            if (! $course = $DB->get_record("course", array("id" => $forum->course))) {
                print_error('coursemisconf');
            }

            // move require_course_login here to use forced language for course
            // fix for MDL-6926
            require_course_login($course, true, $cm);
        } else {
            print_error('missingparameter');
        }

        $context = \context_module::instance($cm->id);

        if (!empty($CFG->enablerssfeeds) && !empty($config->enablerssfeeds) && $forum->rsstype && $forum->rssarticles) {
            require_once("$CFG->libdir/rsslib.php");

            $rsstitle = format_string($course->shortname, true, array('context' => \context_course::instance($course->id))) . ': ' . format_string($forum->name);
            rss_add_http_header($context, 'mod_forumimproved', $forum, $rsstitle);
        }

        // Mark viewed if required
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm);

        /// Some capability checks.
        if (empty($cm->visible) and !has_capability('moodle/course:viewhiddenactivities', $context)) {
            notice(get_string("activityiscurrentlyhidden"));
        }

        if (!has_capability('mod/forumimproved:viewdiscussion', $context)) {
            notice(get_string('noviewdiscussionspermission', 'forumimproved'));
        }

        $params = array(
            'context' => $context,
            'objectid' => $forum->id
        );
        $event = \mod_forumimproved\event\course_module_viewed::create($params);
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('forumimproved', $forum);
        $event->trigger();

        if (!defined(AJAX_SCRIPT) || !AJAX_SCRIPT) {
            // Return here if we post or set subscription etc (but not if we are calling this via ajax).
            $SESSION->fromdiscussion = qualified_me();
        }

        $PAGE->requires->js_init_call('M.mod_forumimproved.init', null, false, $this->get_js_module());
        $output .= $this->svg_sprite();
        $this->view($course, $cm, $forum, $context);

        $url = new \moodle_url('/mod/forumimproved/index.php', ['id' => $course->id]);
        $manageforumsubscriptions = get_string('manageforumsubscriptions', 'mod_forumimproved');
        $output .= \html_writer::link($url, $manageforumsubscriptions);

        $output = ob_get_contents().$output;

        ob_end_clean();

        return ($output);
    }

    /**
     * Render a list of discussions
     *
     * @param \stdClass $cm The forum course module
     * @param array $discussions A list of discussion and discussion post pairs, EG: array(array($discussion, $post), ...)
     * @param array $options Display options and information, EG: total discussions, page number and discussions per page
     * @return string
     */
    public function discussions($cm, array $discussions, array $options) {

        $output = '<div class="forumimproved-new-discussion-target"></div>';
        foreach ($discussions as $discussionpost) {
            list($discussion, $post) = $discussionpost;
            $output .= $this->discussion($cm, $discussion, $post, false);
        }


        // TODO - this is confusing code
        return $this->notification_area().
            $this->output->container('', 'forumimproved-add-discussion-target').
            html_writer::tag('section', $output, array('role' => 'region', 'aria-label' => get_string('discussions', 'forumimproved'), 'class' => 'forumimproved-threads-wrapper', 'tabindex' => '-1')).
            $this->article_assets($cm);

    }

    /**
     * Render a single, stand alone discussion
     *
     * This is very similar to discussion(), but allows for
     * wrapping a single discussion in extra renderings
     * when the discussion is the only thing being viewed
     * on the page.
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The discussion to render
     * @param \stdClass $post The discussion's post to render
     * @param \stdClass[] $posts The discussion posts
     * @param null|boolean $canreply If the user can reply or not (optional)
     * @return string
     */
    public function discussion_thread($cm, $discussion, $post, array $posts, $canreply = null) {
        $output  = $this->discussion($cm, $discussion, $post, true, $posts, $canreply);
        $output .= $this->article_assets($cm);

        return $output;
    }

    /**
     * Render a single discussion
     *
     * Optionally also render the discussion's posts
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The discussion to render
     * @param \stdClass $post The discussion's post to render
     * @param \stdClass[] $posts The discussion posts (optional)
     * @param null|boolean $canreply If the user can reply or not (optional)
     * @return string
     */
    public function discussion($cm, $discussion, $post, $fullthread, array $posts = array(), $canreply = null) {
        global $DB, $PAGE, $USER;

        $forum = forumimproved_get_cm_forum($cm);
        $postuser = forumimproved_extract_postuser($post, $forum, context_module::instance($cm->id));
        $postuser->user_picture->size = 100;

        $course = forumimproved_get_cm_course($cm);
        if (is_null($canreply)) {
            $canreply = forumimproved_user_can_post($forum, $discussion, null, $cm, $course, context_module::instance($cm->id));
        }
        // Meta properties, sometimes don't exist.
        if (!property_exists($discussion, 'replies')) {
            if (!empty($posts)) {
                $discussion->replies = count($posts) - 1;
            } else {
                $discussion->replies = 0;
            }
        } else if (empty($discussion->replies)) {
            $discussion->replies = 0;
        }
        if (!property_exists($discussion, 'unread') or empty($discussion->unread)) {
            $discussion->unread = '-';
        }
        $format = get_string('articledateformat', 'forumimproved');

        $groups = groups_get_all_groups($course->id, 0, $cm->groupingid);
        $group = '';
        if (groups_get_activity_groupmode($cm, $course) > 0 && isset($groups[$discussion->groupid])) {
            $group = $groups[$discussion->groupid];
            $group = format_string($group->name);
        }

        $data           = new stdClass;
        $data->id       = $discussion->id;
        $data->state    = $discussion->state;
        $data->postid   = $post->id;
        $data->unread   = $discussion->unread;
        $data->fullname = $postuser->fullname;
        $data->subject  = $this->raw_post_subject($post);
        $data->message  = $this->post_message($post, $cm);
        $data->created  = userdate($post->created, $format);
        $data->datetime = date(DATE_W3C, usertime($post->created));
        $data->modified = userdate($discussion->timemodified, $format);
        $data->replies  = $discussion->replies;
        $data->replyavatars = array();
        if ($data->replies > 0) {
            // Get actual replies
            $fields = user_picture::fields('u');
            $replyusers = $DB->get_records_sql("SELECT DISTINCT $fields FROM {forumimproved_posts} hp JOIN {user} u ON hp.userid = u.id WHERE hp.discussion = ? AND hp.privatereply = 0 ORDER BY hp.modified DESC", array($discussion->id));
            if (!empty($replyusers) && !$forum->anonymous) {
                foreach ($replyusers as $replyuser) {
                    if ($replyuser->id === $postuser->id) {
                        continue; // Don't show the posters avatar in the reply section.
                    }
                    $replyuser->imagealt = fullname($replyuser);
                    $data->replyavatars[] = $this->output->user_picture($replyuser, array('link' => false, 'size' => 100));
                }
            }
        }
        $data->group      = $group;
        $data->imagesrc   = $postuser->user_picture->get_url($this->page)->out();
        $data->userurl    = $this->get_post_user_url($cm, $postuser);
        $data->viewurl    = new moodle_url('/mod/forumimproved/discuss.php', array('d' => $discussion->id));
        $data->tools      = implode(' ', $this->post_get_commands($post, $discussion, $cm, $canreply));
        $data->postflags  = implode(' ',$this->post_get_flags($post, $cm, $discussion->id));
        $data->subscribe  = '';
        $data->posts      = '';
        $data->fullthread = $fullthread;
        $data->revealed   = false;
        $data->rawcreated = $post->created;
        $data->rawmodified = $post->modified;

        if ($forum->anonymous
                && $postuser->id === $USER->id
                && $post->reveal) {
            $data->revealed         = true;
        }

        if ($fullthread && $canreply) {
            $data->replyform = html_writer::tag(
                'div', $this->simple_edit_post($cm, false, $post->id), array('class' => 'forumimproved-footer-reply')
            );
        } else {
            $data->replyform = '';
        }

        if ($fullthread) {
            $data->posts = $this->posts($cm, $discussion, $posts, $canreply);
        }

        $subscribe = new forumimproved_lib_discussion_subscribe($forum, context_module::instance($cm->id));
        $data->subscribe = $this->discussion_subscribe_link($cm, $discussion, $subscribe) ;

        return $this->discussion_template($data, $forum, $cm);
    }

    public function article_assets($cm) {
        $context = context_module::instance($cm->id);
        $this->article_js($context);
        $output = html_writer::tag(
            'script',
            $this->simple_edit_post($cm),
            array('type' => 'text/template', 'id' => 'forumimproved-reply-template')
        );
        $output .= html_writer::tag(
            'script',
            $this->simple_edit_discussion($cm),
            array('type' => 'text/template', 'id' => 'forumimproved-discussion-template')
        );
        return $output;
    }

    /**
     * Render a single post
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The post's discussion
     * @param \stdClass $post The post to render
     * @param bool $canreply
     * @param null|object $parent Optional, parent post
     * @param array $commands Override default post commands
     * @param int $depth Depth of the post
     * @return string
     */
    public function post($cm, $discussion, $post, $canreply = false, $parent = null, $commands = array(), $depth = 0, $search = '') {
        global $USER, $CFG, $DB;

        $forum = forumimproved_get_cm_forum($cm);
        if (!forumimproved_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            // Return a message about why you cannot see the post
            return "<div class='forumimproved-post-content-hidden'>".get_string('forumbodyhidden','forumimproved')."</div>";
        }
        if ($commands === false){
            $commands = array();
        } else if (empty($commands)) {
            $commands = $this->post_get_commands($post, $discussion, $cm, $canreply, false);
        } else if (!is_array($commands)){
            throw new coding_exception('$commands must be false, empty or populated array');
        }
        $postuser = forumimproved_extract_postuser($post, $forum, context_module::instance($cm->id));
        $postuser->user_picture->size = 100;

        // $post->breadcrumb comes from search btw.
        $data                 = new stdClass;
        $data->id             = $post->id;
        $data->discussionid   = $discussion->id;
        $data->fullname       = $postuser->fullname;
        $data->subject        = property_exists($post, 'breadcrumb') ? $post->breadcrumb : $this->raw_post_subject($post);
        $data->message        = $this->post_message($post, $cm, $search);
        $data->created        = userdate($post->created, get_string('articledateformat', 'forumimproved'));
        $data->rawcreated     = $post->created;
        $data->datetime       = date(DATE_W3C, usertime($post->created));
        $data->privatereply   = $post->privatereply;
        $data->imagesrc       = $postuser->user_picture->get_url($this->page)->out();
        $data->userurl        = $this->get_post_user_url($cm, $postuser);
        $data->unread         = empty($post->postread) ? true : false;
        $data->permalink      = new moodle_url('/mod/forumimproved/discuss.php#p'.$post->id, array('d' => $discussion->id));
        $data->isreply        = false;
        $data->parentfullname = '';
        $data->parentuserurl  = '';
        $data->tools          = implode(' ', $commands);
        $data->postflags      = implode(' ',$this->post_get_flags($post, $cm, $discussion->id, false));
        $data->depth          = $depth;
        $data->revealed       = false;

        if ($forum->anonymous
                && $postuser->id === $USER->id
                && $post->reveal) {
            $data->revealed         = true;
        }

        if (!empty($post->children)) {
            $post->replycount = count($post->children);
        }
        $data->replycount = '';
        // Only show reply count if replies and not first post
        if(!empty($post->replycount) && $post->replycount > 0 && $post->parent) {
            $data->replycount = forumimproved_xreplies($post->replycount);
        }

        // Mark post as read.
        if ($data->unread) {
            forumimproved_mark_post_read($USER->id, $post, $forum->id);
        }

        if (!empty($parent)) {
            $parentuser = forumimproved_extract_postuser($parent, $forum, context_module::instance($cm->id));
            $data->parenturl = $CFG->wwwroot.'/mod/forumimproved/discuss.php?d='.$parent->discussion.'#p'.$parent->id;
            $data->parentfullname = $parentuser->fullname;
            if (!empty($parentuser->user_picture)) {
                $parentuser->user_picture->size = 100;
                $data->parentuserurl = $this->get_post_user_url($cm, $parentuser);
                $data->parentuserpic = $this->output->user_picture($parentuser,
                    array('link' => false, 'size' => 100, 'alttext' => false));
            }
        }

        if ($depth > 0) {
            // Top level responses don't count.
            $data->isreply = true;
        }

        return $this->post_template($data);
    }

    public function discussion_template($d, $forum, $cm) {
        $replies = '';
        if(!empty($d->replies)) {
            $xreplies = forumimproved_xreplies($d->replies);
            $replies = "<span class='forumimproved-replycount'>$xreplies</span>";
        }
        if (!empty($d->userurl)) {
            $byuser = html_writer::link($d->userurl, $d->fullname);
        } else {
            $byuser = html_writer::tag('span', $d->fullname);
        }
        $unread = '';
        $unreadclass = '';
        $attrs = '';
        if ($d->unread != '-') {
            $new  = get_string('unread', 'forumimproved');
            $unread  = "<a class='forumimproved-unreadcount disable-router' href='$d->viewurl#unread'>$new</a>";
            $attrs   = 'data-isunread="true"';
            $unreadclass = 'forumimproved-post-unread';
        }

        $author = s(strip_tags($d->fullname));
        $group = '';
        if (!empty($d->group)) {
            $group = '<br>'.$d->group;
        }

        $latestpost = '';
        if (!empty($d->modified) && !empty($d->replies)) {
            $latestpost = '<small class="forumimproved-thread-replies-meta">'.get_string('lastposttimeago', 'forumimproved', forumimproved_relative_time($d->rawmodified)).'</small>';
        }

        $participants = '<div class="forumimproved-thread-participants">'.implode(' ',$d->replyavatars).'</div>';


        $datecreated = forumimproved_relative_time($d->rawcreated, array('class' => 'forumimproved-thread-pubdate'));

        $threadtitle = $d->subject;
        if (!$d->fullthread) {
            $threadtitle = "<a class='disable-router' href='$d->viewurl'>$d->subject</a>";
        }
        $options = get_string('options', 'forumimproved');
        $threadmeta  =
            '<div class="forumimproved-thread-meta">'
                .$replies
                .$unread
                .$participants
                .$latestpost
                .'<div class="forumimproved-thread-flags">'."{$d->subscribe} $d->postflags</div>"
            .'</div>';

        if ($d->fullthread) {
            $tools = '<div role="region" class="forumimproved-tools forumimproved-thread-tools" aria-label="'.$options.'">'.$d->tools.'</div>';
            $blogmeta = '';
            $blogreplies = '';
        } else {
            $blogreplies = forumimproved_xreplies($d->replies);
            $tools = "<a class='disable-router forumimproved-replycount-link' href='$d->viewurl'>$blogreplies</a>";
            $blogmeta = $threadmeta;
        }

        $revealed = "";
        if ($d->revealed) {
            $nonanonymous = get_string('nonanonymous', 'mod_forumimproved');
            $revealed = '<span class="label label-danger">'.$nonanonymous.'</span>';
        }




        $context = \context_module::instance($cm->id);

        $stateLabel= '';
        $buttonToggleState = '';
        
        if ($forum->enable_close_disc) {
            if (forumimproved_is_discussion_closed($forum, $d)) {
                $stateLabel = '<span class="label label-warning">' . get_string('state_thread_close', 'forumimproved') . '</span>';
                $toggleStateButtonLabel = get_string('open_thread_title', 'forumimproved');
            }
            else {
                $toggleStateButtonLabel = get_string('close_thread_title', 'forumimproved');
            }
            
            $buttonToggleState = html_writer::link(
                new moodle_url('/mod/forumimproved/post.php', array(
                    'close' => $d->id,
                    'fullthread' => (int) $d->fullthread
                )),
                $toggleStateButtonLabel,
                array(
                    'class' => 'forumimproved-toggle-state-link btn btn-default'
                )
            );
            if (has_capability('mod/forumimproved:close_discussion', $context)) {
                $buttonToggleState = html_writer::link(
                    new moodle_url('/mod/forumimproved/post.php', array(
                        'close' => $d->id,
                        'fullthread' => (int) $d->fullthread
                    )),
                    $toggleStateButtonLabel,
                    array(
                        'class' => 'forumimproved-toggle-state-link btn btn-default'
                    )
                );
            }
        }






        $threadheader = <<<HTML
        <div class="forumimproved-thread-header">
            <div class="forumimproved-thread-title">
                <h4 id='thread-title-{$d->id}' role="heading" aria-level="4">
                    $stateLabel $threadtitle
                </h4>
                <p><small>$datecreated</small></p>
                <p>$buttonToggleState</p>
            </div>
            $threadmeta
        </div>
HTML;

        return <<<HTML
<article id="p{$d->postid}" class="forumimproved-thread forumimproved-post-target clearfix" role="article"
    data-discussionid="$d->id" data-postid="$d->postid" data-author="$author" data-isdiscussion="true" $attrs>
    <header id="h{$d->postid}" class="clearfix $unreadclass">
        <div class="forumimproved-thread-author">
            <img class="userpicture img-circle" src="{$d->imagesrc}" alt="" />
            <p class="forumimproved-thread-byline">
                $byuser $group $revealed
            </p>
        </div>

        $threadheader

        <div class="forumimproved-thread-content" tabindex="0">
            $d->message
        </div>
        $tools
    </header>

    <div id="forumimproved-thread-{$d->id}" class="forumimproved-thread-body">
        <!-- specific to blog style -->
        $blogmeta
        $d->posts
        $d->replyform
    </div>
</article>
HTML;
    }

    /**
     * Render a list of posts
     *
     * @param \stdClass $cm The forum course module
     * @param \stdClass $discussion The discussion for the posts
     * @param \stdClass[] $posts The posts to render
     * @param bool $canreply
     * @throws coding_exception
     * @return string
     */
    public function posts($cm, $discussion, $posts, $canreply = false) {
        global $USER;

        $items = '';
        $count = 0;
        if (!empty($posts)) {
            if (!array_key_exists($discussion->firstpost, $posts)) {
                throw new coding_exception('Missing discussion post');
            }
            $parent = $posts[$discussion->firstpost];
            $items .= $this->post_walker($cm, $discussion, $posts, $parent, $canreply, $count);

            // Mark post as read.
            if (empty($parent->postread)) {
                $forum = forumimproved_get_cm_forum($cm);
                forumimproved_mark_post_read($USER->id, $parent, $forum->id);
            }
        }
        $output  = "<h5 role='heading' aria-level='5'>".forumimproved_xreplies($count)."</h5>";
        if (!empty($count)) {
            $output .= "<ol class='forumimproved-thread-replies-list'>".$items."</ol>";
        }
        return "<div class='forumimproved-thread-replies'>".$output."</div>";
    }

    /**
     * Internal method to walk over a list of posts, rendering
     * each post and their children.
     *
     * @param object $cm
     * @param object $discussion
     * @param array $posts
     * @param object $parent
     * @param bool $canreply
     * @param int $count Keep track of the number of posts actually rendered
     * @param int $depth
     * @return string
     */
    protected function post_walker($cm, $discussion, $posts, $parent, $canreply, &$count, $depth = 0) {
        $output = '';
        foreach ($posts as $post) {
            if ($post->parent != $parent->id) {
                continue;
            }
            $html = $this->post($cm, $discussion, $post, $canreply, $parent, array(), $depth);
            if (!empty($html)) {
                $count++;
                $output .= "<li class='forumimproved-post depth$depth' data-depth='$depth' data-count='$count'>".$html."</li>";

                if (!empty($post->children)) {
                    $output .= $this->post_walker($cm, $discussion, $posts, $post, $canreply, $count, ($depth + 1));
                }
            }
        }
        return $output;
    }

    /**
     * Return html for individual post
     *
     * 3 use cases:
     *  1. Standard post
     *  2. Reply to user
     *  3. Private reply to user
     *
     * @param object $p
     * @return string
     */
    public function post_template($p) {
        global $PAGE;

        $byuser = $p->fullname;
        if (!empty($p->userurl)) {
            $byuser = html_writer::link($p->userurl, $p->fullname);
        }
        $byline = get_string('postbyx', 'forumimproved', $byuser);
        if ($p->isreply) {
            $parent = $p->parentfullname;
            if (!empty($p->parentuserurl)) {
                $parent = html_writer::link($p->parentuserurl, $p->parentfullname);
            }
            if (empty($p->parentuserpic)) {
                $byline = get_string('replybyx', 'forumimproved', $byuser);
            } else {
                $byline = get_string('postbyxinreplytox', 'forumimproved', array(
                        'parent' => $p->parentuserpic.$parent,
                        'author' => $byuser,
                        'parentpost' => "<a title='".get_string('parentofthispost', 'forumimproved')."' class='forumimproved-parent-post-link disable-router' href='$p->parenturl'><span class='accesshide'>".get_string('parentofthispost', 'forumimproved')."</span>↑</a>"
                ));
            }
            if (!empty($p->privatereply)) {
                if (empty($p->parentuserpic)) {
                    $byline = get_string('privatereplybyx', 'forumimproved', $byuser);
                } else {
                    $byline = get_string('postbyxinprivatereplytox', 'forumimproved', array(
                            'author' => $byuser,
                            'parent' => $p->parentuserpic.$parent
                        ));
                }
            }
        } else if (!empty($p->privatereply)) {
            $byline = get_string('privatereplybyx', 'forumimproved', $byuser);
        }

        $author = s(strip_tags($p->fullname));
        $unread = '';
        $unreadclass = '';
        if ($p->unread) {
            $unread = "<span class='forumimproved-unreadcount'>".get_string('unread', 'forumimproved')."</span>";
            $unreadclass = "forumimproved-post-unread";
        }
        $options = get_string('options', 'forumimproved');
        $datecreated = forumimproved_relative_time($p->rawcreated, array('class' => 'forumimproved-post-pubdate'));


        $postreplies = '';
        if($p->replycount) {
            $postreplies = "<div class='post-reply-count accesshide'>$p->replycount</div>";
        }

        $newwindow = '';
        if ($PAGE->pagetype === 'local-joulegrader-view') {
            $newwindow = ' target="_blank"';
        }

        $revealed = "";
        if ($p->revealed) {
            $nonanonymous = get_string('nonanonymous', 'mod_forumimproved');
            $revealed = '<span class="label label-danger">'.$nonanonymous.'</span>';
        }

 return <<<HTML
<div class="forumimproved-post-wrapper forumimproved-post-target clearfix $unreadclass" id="p$p->id" data-postid="$p->id" data-discussionid="$p->discussionid" data-author="$author" data-ispost="true" tabindex="-1">

    <div class="forumimproved-post-figure">

        <img class="userpicture" src="{$p->imagesrc}" alt="">

    </div>
    <div class="forumimproved-post-body">
        <h6 role="heading" aria-level="6" class="forumimproved-post-byline" id="forumimproved-post-$p->id">
            $unread $byline $revealed
        </h6>
        <small class='improvedform-post-date'><a href="$p->permalink" class="disable-router"$newwindow>$datecreated</a></small>

        <div class="forumimproved-post-content">
            <div class="forumimproved-post-title">$p->subject</div>
                $p->message
        </div>
        <div role="region" class='forumimproved-tools' aria-label='$options'>
            <div class="forumimproved-postflagging">$p->postflags</div>
            $p->tools
        </div>
        $postreplies
    </div>
</div>
HTML;
    }

    /**
     * This method is used to generate HTML for a subscriber selection form that
     * uses two user_selector controls
     *
     * @param user_selector_base $existinguc
     * @param user_selector_base $potentialuc
     * @return string
     */
    public function subscriber_selection_form(user_selector_base $existinguc, user_selector_base $potentialuc) {
        $output = '';
        $formattributes = array();
        $formattributes['id'] = 'subscriberform';
        $formattributes['action'] = '';
        $formattributes['method'] = 'post';
        $output .= html_writer::start_tag('form', $formattributes);
        $output .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()));

        $existingcell = new html_table_cell();
        $existingcell->text = $existinguc->display(true);
        $existingcell->attributes['class'] = 'existing';
        $actioncell = new html_table_cell();
        $actioncell->text  = html_writer::start_tag('div', array());
        $actioncell->text .= html_writer::empty_tag('input', array('type'=>'submit', 'name'=>'subscribe', 'value'=>$this->page->theme->larrow.' '.get_string('add'), 'class'=>'actionbutton'));
        $actioncell->text .= html_writer::empty_tag('br', array());
        $actioncell->text .= html_writer::empty_tag('input', array('type'=>'submit', 'name'=>'unsubscribe', 'value'=>$this->page->theme->rarrow.' '.get_string('remove'), 'class'=>'actionbutton'));
        $actioncell->text .= html_writer::end_tag('div', array());
        $actioncell->attributes['class'] = 'actions';
        $potentialcell = new html_table_cell();
        $potentialcell->text = $potentialuc->display(true);
        $potentialcell->attributes['class'] = 'potential';

        $table = new html_table();
        $table->attributes['class'] = 'subscribertable boxaligncenter';
        $table->data = array(new html_table_row(array($existingcell, $actioncell, $potentialcell)));
        $output .= html_writer::table($table);

        $output .= html_writer::end_tag('form');
        return $output;
    }

    /**
     * This function generates HTML to display a subscriber overview, primarily used on
     * the subscribers page if editing was turned off
     *
     * @param array $users
     * @param string $entityname
     * @param object $forum
     * @param object $course
     * @return string
     */
    public function subscriber_overview($users, $entityname, $forum, $course) {
        $output = '';
        $modinfo = get_fast_modinfo($course);
        if (!$users || !is_array($users) || count($users)===0) {
            $output .= $this->output->heading(get_string("nosubscribers", "forumimproved"));
        } else if (!isset($modinfo->instances['forumimproved'][$forum->id])) {
            $output .= $this->output->heading(get_string("invalidmodule", "error"));
        } else {
            $cm = $modinfo->instances['forumimproved'][$forum->id];
            $canviewemail = in_array('email', get_extra_user_fields(context_module::instance($cm->id)));
            $output .= $this->output->heading(get_string("subscribersto","forumimproved", "'".format_string($entityname)."'"));
            $table = new html_table();
            $table->cellpadding = 5;
            $table->cellspacing = 5;
            $table->tablealign = 'center';
            $table->data = array();
            foreach ($users as $user) {
                $info = array($this->output->user_picture($user, array('courseid'=>$course->id)), fullname($user));
                if ($canviewemail) {
                    array_push($info, $user->email);
                }
                $table->data[] = $info;
            }
            $output .= html_writer::table($table);
        }
        return $output;
    }

    /**
     * This is used to display a control containing all of the subscribed users so that
     * it can be searched
     *
     * @param user_selector_base $existingusers
     * @return string
     */
    public function subscribed_users(user_selector_base $existingusers) {
        $output  = $this->output->box_start('subscriberdiv boxaligncenter');
        $output .= html_writer::tag('p', get_string('forcessubscribe', 'forumimproved'));
        $output .= $existingusers->display(true);
        $output .= $this->output->box_end();
        return $output;
    }

    /**
     * The javascript module used by the presentation layer
     *
     * @return array
     * @author Mark Nielsen
     */
    public function get_js_module() {
        return array(
            'name'      => 'mod_forumimproved',
            'fullpath'  => '/mod/forumimproved/module.js',
            'requires'  => array(
                'base',
                'node',
                'event',
                'anim',
                'panel',
                'dd-plugin',
                'io-base',
                'json',
                'core_rating',
            ),
            'strings' => array(
                array('jsondecodeerror', 'forumimproved'),
                array('ajaxrequesterror', 'forumimproved'),
                array('clicktoexpand', 'forumimproved'),
                array('clicktocollapse', 'forumimproved'),
                array('manualwarning', 'forumimproved'),
                array('subscribeshort', 'forumimproved'),
                array('unsubscribeshort', 'forumimproved'),
                array('useadvancededitor', 'forumimproved'),
                array('hideadvancededitor', 'forumimproved'),
                array('loadingeditor', 'forumimproved'),
                array('toggle:bookmark', 'forumimproved'),
                array('toggle:subscribe', 'forumimproved'),
                array('toggle:substantive', 'forumimproved'),
                array('toggled:bookmark', 'forumimproved'),
                array('toggled:subscribe', 'forumimproved'),
                array('toggled:substantive', 'forumimproved')

            )
        );
    }

    /**
     * Output substantive / bookmark toggles
     *
     * @param stdClass $post The post to add flags to
     * @param $cm
     * @param int $discussion id of parent discussion
     * @param bool $reply is this the first post in a thread or a reply
     * @throws coding_exception
     * @return array
     * @author Mark Nielsen
     */
    public function post_get_flags($post, $cm, $discussion, $reply = false) {
        global $PAGE, $CFG;

        $context = context_module::instance($cm->id);

        if (!has_capability('mod/forumimproved:viewflags', $context)) {
            return array();
        }
        if (!property_exists($post, 'flags')) {
            throw new coding_exception('The post\'s flags property must be set');
        }
        require_once(__DIR__.'/lib/flag.php');

        $flaglib   = new forumimproved_lib_flag();
        $canedit   = has_capability('mod/forumimproved:editanypost', $context);
        $returnurl = $this->return_url($cm->id, $discussion);

        $flaghtml = array();

        $forum = forumimproved_get_cm_forum($cm);
        foreach ($flaglib->get_flags() as $flag) {

            // Skip bookmark flagging if switched off at forum level or if global kill switch set.
            if ($flag == 'bookmark') {
                if ($forum->showbookmark === '0') {
                    continue;
                }
            }

            // Skip substantive flagging if switched off at forum level or if global kill switch set.
            if ($flag == 'substantive') {
                if ($forum->showsubstantive === '0') {
                    continue;
                }
            }

            $isflagged = $flaglib->is_flagged($post->flags, $flag);

            $url = new moodle_url('/mod/forumimproved/route.php', array(
                'contextid' => $context->id,
                'action'    => 'flag',
                'returnurl' => $returnurl,
                'postid'    => $post->id,
                'flag'      => $flag,
                'sesskey'   => sesskey()
            ));

            // Create appropriate area described by.
            $describedby = $reply ? 'thread-title-'.$discussion : 'forumimproved-post-'.$post->id;

            // Create toggle element.
            $flaghtml[$flag] = $this->toggle_element($flag,
                $describedby,
                $url,
                $isflagged,
                $canedit,
                array('class' => 'forumimproved_flag')
            );

        }

        return $flaghtml;
    }

    /**
     * Return Url for non-ajax fallback.
     *
     * When $PAGE is a route we need to create on based on the action
     * parameter.
     *
     * @param int $cmid
     * @param int $discussionid
     * @return string url
     */
    private function return_url($cmid, $discussionid) {
        global $PAGE;
        if (strpos($PAGE->url, 'route.php') === false) {
            return $PAGE->url;
        }
        $action = $PAGE->url->param('action');
        if ($action === 'add_discussion' ) {
            return "view.php?id=$cmid";
        } else if ($action === 'reply') {
            return "discuss.php?id=$discussionid";
        }
    }

    /**
     * Output a toggle_element.
     *
     * @param string $type - toggle type
     * @param string $label - label for toggle element
     * @param string $describedby - aria described by
     * @param moodle_url $url
     * @param bool $pressed
     * @param bool $link
     * @param null $attributes
     * @return string
     */
    public function toggle_element($type, $describedby, $url, $pressed = false, $link = true, $attributes = null) {
        if ($pressed) {
            $label = get_string('toggled:'.$type, 'forumimproved');
        } else {
            $label = get_string('toggle:'.$type, 'forumimproved');
        }
        if (empty($attributes)) {
            $attributes = array();
        }
        if (!isset($attributes['class'])){
            $attributes['class'] = '';
        }
        $classes = array($attributes['class'], 'forumimproved-toggle forumimproved-toggle-'.$type);
        if ($pressed) {
            $classes[] = 'forumimproved-toggled';
        }
        $classes = array_filter($classes);
        // Re-add classes to attributes.
        $attributes['class'] = implode(' ', $classes);
        $icon = '<svg viewBox="0 0 100 100" class="svg-icon '.$type.'">
        <title>'.$label.'</title>
        <use xlink:href="#'.$type.'"></use></svg>';
        if ($link) {
            $attributes['role']       = 'button';
            $attributes['data-toggletype'] = $type;
            $attributes['aria-pressed'] = $pressed ? 'true' :  'false';
            $attributes['aria-describedby'] = $describedby;
            $attributes['title']       = $type;
            return (html_writer::link($url, $icon, $attributes));
        } else {
            return (html_writer::tag('span', $icon, $attributes));
        }
    }

    /**
     * Adds a link to subscribe to a disussion
     *
     * @param stdClass $cm
     * @param stdClass $discussion
     * @param forumimproved_lib_discussion_subscribe $subscribe
     * @return string
     * @author Mark Nielsen / Guy Thomas
     */
    public function discussion_subscribe_link($cm, $discussion, forumimproved_lib_discussion_subscribe $subscribe) {

        if (!$subscribe->can_subscribe()) {
            return;
        }

        $returnurl = $this->return_url($cm->id, $discussion->id);

        $url = new moodle_url('/mod/forumimproved/route.php', array(
            'contextid'    => context_module::instance($cm->id)->id,
            'action'       => 'subscribedisc',
            'discussionid' => $discussion->id,
            'sesskey'      => sesskey(),
            'returnurl'    => $returnurl,
        ));

        $o = $this->toggle_element('subscribe',
            'thread-title-'.$discussion->id,
            $url,
            $subscribe->is_subscribed($discussion->id),
            true,
            array('class' => 'forumimproved_discussion_subscribe')
        );
        return $o;
    }

    /**
     * @param $cm
     * @param forumimproved_lib_discussion_sort $sort
     * @return string
     * @author Mark Nielsen
     */
    public function discussion_sorting($cm, forumimproved_lib_discussion_sort $sort) {

        $url = new moodle_url('/mod/forumimproved/view.php');

        $sortselect = html_writer::select($sort->get_key_options_menu(), 'dsortkey', $sort->get_key(), false, array('class' => ''));
        $sortform = "<form method='get' action='$url' class='forumimproved-discussion-sort'>
                    <legend class='accesshide'>".get_string('sortdiscussions', 'forumimproved')."</legend>
                    <input type='hidden' name='id' value='{$cm->id}'>
                    <label for='dsortkey' class='accesshide'>".get_string('orderdiscussionsby', 'forumimproved')."</label>
                    $sortselect
                    <input type='submit' value='".get_string('sortdiscussionsby', 'forumimproved')."'>
                    </form>";

        return $sortform;
    }

    /**
     * @param stdClass $post
     * @return string
     */
    public function raw_post_subject($post) {
        if (empty($post->subjectnoformat)) {
            return format_string($post->subject);
        }
        return $post->subject;
    }

    /**
     * @param stdClass $post
     * @param stdClass $cm
     * @return string
     * @author Mark Nielsen
     */
    public function post_message($post, $cm, $search = '') {

        $options = new stdClass;
        $options->para    = false;
        $options->trusted = $post->messagetrust;
        $options->context = context_module::instance($cm->id);

        list($attachments, $attachedimages) = forumimproved_print_attachments($post, $cm, 'separateimages');

        $message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', context_module::instance($cm->id)->id, 'mod_forumimproved', 'post', $post->id);

        $postcontent = format_text($message, $post->messageformat, $options, $cm->course);

        if (!empty($search)) {
            $postcontent = highlight($search, $postcontent);
        }

        if (!empty($attachments)) {
            $postcontent .= "<div class='attachments'>".$attachments."</div>";
        }
        if (!empty($attachedimages)) {
            $postcontent .= "<div class='attachedimages'>".$attachedimages."</div>";
        }



        $forum = forumimproved_get_cm_forum($cm);
        if (!empty($forum->displaywordcount)) {
            $postcontent .= "<div class='post-word-count'>".get_string('numwords', 'moodle', count_words($post->message))."</div>";
        }
        $postcontent  = "<div class='posting'>".$postcontent."</div>";
        return $postcontent;
    }

    /**
     * @param stdClass $post
     * @return string
     * @author Mark Nielsen
     */
    public function post_rating($post) {
        $output = '';
        if (!empty($post->rating)) {
            $rendered = $this->render($post->rating);
            if (!empty($rendered)) {
                $output = "<div class='forum-post-rating'>".$rendered."</div>";
            }
        }
        return $output;
    }





    /**
     * @param $userid
     * @param $cm
     * @param null|stdClass $showonlypreference
     *
     * @return string
     * @author Mark Nielsen
     */
    public function user_posts_overview($userid, $cm, $showonlypreference = null) {
        global $PAGE;

        require_once(__DIR__.'/lib/flag.php');

        $config = get_config('forumimproved');

        $forum = forumimproved_get_cm_forum($cm);

        $showonlypreferencebutton = '';
        if (!empty($showonlypreference) and !empty($showonlypreference->button) and !$forum->anonymous) {
            $showonlypreferencebutton = $showonlypreference->button;
        }

        $output    = '';
        $postcount = $discussioncount = $flagcount = 0;
        $flaglib   = new forumimproved_lib_flag();
        if ($posts = forumimproved_get_user_posts($forum->id, $userid, context_module::instance($cm->id))) {
            $discussions = forumimproved_get_user_involved_discussions($forum->id, $userid);
            if (!empty($showonlypreference) and !empty($showonlypreference->preference)) {
                foreach ($discussions as $discussion) {

                    if ($discussion->userid == $userid and array_key_exists($discussion->firstpost, $posts)) {
                        $discussionpost = $posts[$discussion->firstpost];

                        $discussioncount++;
                        if ($flaglib->is_flagged($discussionpost->flags, 'substantive')) {
                            $flagcount++;
                        }
                    } else {
                        if (!$discussionpost = forumimproved_get_post_full($discussion->firstpost)) {
                            continue;
                        }
                    }
                    if (!$forum->anonymous) {
                        $output .= $this->post($cm, $discussion, $discussionpost, false, null, false);

                        $output .= html_writer::start_tag('div', array('class' => 'indent'));
                    }
                    foreach ($posts as $post) {
                        if ($post->discussion == $discussion->id and !empty($post->parent)) {
                            $postcount++;
                            if ($flaglib->is_flagged($post->flags, 'substantive')) {
                                $flagcount++;
                            }
                            $command = html_writer::link(
                                new moodle_url('/mod/forumimproved/discuss.php', array('d' => $discussion->id), 'p'.$post->id),
                                get_string('postincontext', 'forumimproved'),
                                array('target' => '_blank')
                            );
                            if (!$forum->anonymous) {
                                $output .= $this->post($cm, $discussion, $post, false, null, false);
                            }
                        }
                    }
                    if (!$forum->anonymous) {
                        $output .= html_writer::end_tag('div');
                    }
                }
            } else {
                foreach ($posts as $post) {
                    if (!empty($post->parent)) {
                        $postcount++;
                    } else {
                        $discussioncount++;
                    }
                    if ($flaglib->is_flagged($post->flags, 'substantive')) {
                        $flagcount++;
                    }
                    if (!$forum->anonymous) {
                        $command = html_writer::link(
                            new moodle_url('/mod/forumimproved/discuss.php', array('d' => $post->discussion), 'p'.$post->id),
                            get_string('postincontext', 'forumimproved'),
                            array('target' => '_blank')
                        );
                        $output .= $this->post($cm, $discussions[$post->discussion], $post, false, null, false);
                    }
                }
            }
        }
        if (!empty($postcount) or !empty($discussioncount)) {

            if ($forum->anonymous) {
                $output = html_writer::tag('h3', get_string('thisisanonymous', 'forumimproved'));
            }
            $counts = array(
                get_string('totalpostsanddiscussions', 'forumimproved', ($discussioncount+$postcount)),
                get_string('totaldiscussions', 'forumimproved', $discussioncount),
                get_string('totalreplies', 'forumimproved', $postcount)
            );

            if (!empty($config->showsubstantive)) {
                $counts[] = get_string('totalsubstantive', 'forumimproved', $flagcount);
            }

            if ($grade = forumimproved_get_user_formatted_rating_grade($forum, $userid)) {
                $counts[] = get_string('totalrating', 'forumimproved', $grade);
            }
            $countshtml = '';
            foreach ($counts as $count) {
                $countshtml .= html_writer::tag('div', $count, array('class' => 'forumimproved_count'));
            }
            $output = html_writer::div($countshtml, 'forumimproved_counts').$showonlypreferencebutton.$output;
            $output = html_writer::div($output, 'mod-forumimproved-posts-container article');
        }
        return $output;
    }

    /**
     * @param Exception[] $errors
     * @return string;
     */
    public function validation_errors($errors) {
        $message = '';
        if (count($errors) == 1) {
            $error = current($errors);
            $message = get_string('validationerrorx', 'forumimproved', $error->getMessage());
        } else if (count($errors) > 1) {
            $items = array();
            foreach ($errors as $error) {
                $items[] = $error->getMessage();
            }
            $message = get_string('validationerrorsx', 'forumimproved', array(
                'count'  => count($errors),
                'errors' => html_writer::alist($items, null, 'ol'),
            ));
        }
        return $message;
    }

    /**
     * Get the simple edit discussion form
     *
     * @param object $cm
     * @param int $postid
     * @param array $data Template data
     * @return string
     */
    public function simple_edit_discussion($cm, $postid = 0, array $data = array()) {
        global $DB, $USER, $OUTPUT;

        $context = context_module::instance($cm->id);
        $forum = forumimproved_get_cm_forum($cm);

        if (!empty($postid)) {
            $params = array('edit' => $postid);
            $legend = get_string('editingpost', 'forumimproved');
            $post = $DB->get_record('forumimproved_posts', ['id' => $postid]);
            if ($post->userid != $USER->id) {
                $user = $DB->get_record('user', ['id' => $post->userid]);
                $user = forumimproved_anonymize_user($user, $forum, $post);
                $data['userpicture'] = $this->output->user_picture($user, array('link' => false, 'size' => 100));
            }
        } else {
            $params  = array('forum' => $cm->instance);
            $legend = get_string('addyourdiscussion', 'forumimproved');
            $thresholdwarning = forumimproved_check_throttling($forum, $cm);
            if (!empty($thresholdwarning)) {
                $message = get_string($thresholdwarning->errorcode, $thresholdwarning->module, $thresholdwarning->additional);
                $data['thresholdwarning'] = $OUTPUT->notification($message);
                if ($thresholdwarning->canpost === false) {
                    $data['thresholdblocked'] = " forumimproved-threshold-blocked ";
                }
            }
        }

        $data += array(
            'itemid'        => 0,
            'groupid'       => 0,
            'messageformat' => FORMAT_HTML,
        );
        $actionurl = new moodle_url('/mod/forumimproved/route.php', array(
            'action'        => (empty($postid)) ? 'add_discussion' : 'update_post',
            'sesskey'       => sesskey(),
            'edit'          => $postid,
            'contextid'     => $context->id,
            'itemid'        => $data['itemid'],
            'messageformat' => $data['messageformat'],
        ));

        $extrahtml = '';
        if (groups_get_activity_groupmode($cm)) {
            $groupdata = groups_get_activity_allowed_groups($cm);
            if (count($groupdata) > 1 && has_capability('mod/forumimproved:movediscussions', $context)) {
                $groupinfo = array('0' => get_string('allparticipants'));
                foreach ($groupdata as $grouptemp) {
                    $groupinfo[$grouptemp->id] = $grouptemp->name;
                }
                $extrahtml = html_writer::tag('span', get_string('group'));
                $extrahtml .= html_writer::select($groupinfo, 'groupinfo', $data['groupid'], false);
                $extrahtml = html_writer::tag('label', $extrahtml);
            } else {
                $actionurl->param('groupinfo', groups_get_activity_group($cm));
            }
        }
        if ($forum->anonymous) {
            $extrahtml .= html_writer::tag('label', html_writer::checkbox('reveal', 1, !empty($data['reveal'])).
                get_string('reveal', 'forumimproved'));
        }
        $data += array(
            'postid'      => $postid,
            'context'     => $context,
            'forum'       => $forum,
            'actionurl'   => $actionurl,
            'class'       => 'forumimproved-discussion',
            'legend'      => $legend,
            'extrahtml'   => $extrahtml,
            'advancedurl' => new moodle_url('/mod/forumimproved/post.php', $params),
        );
        return $this->simple_edit_template($data);
    }

    /**
     * Get the simple edit post form
     *
     * @param object $cm
     * @param bool $isedit If we are editing or not
     * @param int $postid If editing, then the ID of the post we are editing. If
     *                    not editing, then the ID of the post we are replying to.
     * @param array $data Template data
     * @return string
     */
    public function simple_edit_post($cm, $isedit = false, $postid = 0, array $data = array()) {
        global $DB, $CFG, $USER, $OUTPUT;

        $context = context_module::instance($cm->id);
        $forum = forumimproved_get_cm_forum($cm);
        $postuser = $USER;
        $ownpost = false;

        if ($isedit) {
            $param  = 'edit';
            $legend = get_string('editingpost', 'forumimproved');
            $post = $DB->get_record('forumimproved_posts', ['id' => $postid]);
            if ($post->userid == $USER->id) {
                $ownpost = true;
            } else {
                $postuser = $DB->get_record('user', ['id' => $post->userid]);
                $postuser = forumimproved_anonymize_user($postuser, $forum, $post);
                $data['userpicture'] = $this->output->user_picture($postuser, array('link' => false, 'size' => 100));
            }
        } else {
            // It is a reply, AKA new post
            $ownpost = true;
            $param  = 'reply';
            $legend = get_string('addareply', 'forumimproved');
            $thresholdwarning = forumimproved_check_throttling($forum, $cm);
            if (!empty($thresholdwarning)) {
                $message = get_string($thresholdwarning->errorcode, $thresholdwarning->module, $thresholdwarning->additional);
                $data['thresholdwarning'] = $OUTPUT->notification($message);
                if ($thresholdwarning->canpost === false) {
                    $data['thresholdblocked'] = " forumimproved-threshold-blocked ";
                }
            }
        }

        $data += array(
            'itemid'        => 0,
            'privatereply'  => 0,
            'reveal'        => 0,
            'messageformat' => FORMAT_HTML,
        );
        $actionurl = new moodle_url('/mod/forumimproved/route.php', array(
            'action'        => ($isedit) ? 'update_post' : 'reply',
            $param          => $postid,
            'sesskey'       => sesskey(),
            'contextid'     => $context->id,
            'itemid'        => $data['itemid'],
            'messageformat' => $data['messageformat'],
        ));

        $extrahtml = '';
        if (has_capability('mod/forumimproved:allowprivate', $context, $postuser)
            && $forum->allowprivatereplies !== '0'
        ) {
            $extrahtml .= html_writer::tag('label', html_writer::checkbox('privatereply', 1, !empty($data['privatereply'])).
                get_string('privatereply', 'forumimproved'));
        }
        if ($forum->anonymous && !$isedit
            || $forum->anonymous && $isedit && $ownpost) {
            $extrahtml .= html_writer::tag('label', html_writer::checkbox('reveal', 1, !empty($data['reveal'])).
                get_string('reveal', 'forumimproved'));
        }
        $data += array(
            'postid'          => ($isedit) ? $postid : 0,
            'context'         => $context,
            'forum'           => $forum,
            'actionurl'       => $actionurl,
            'class'           => 'forumimproved-reply',
            'legend'          => $legend,
            'extrahtml'       => $extrahtml,
            'subjectrequired' => $isedit,
            'advancedurl'     => new moodle_url('/mod/forumimproved/post.php', array($param => $postid)),
        );
        return $this->simple_edit_template($data);
    }

    /**
     * The simple edit template
     *
     * @param array $t The letter "t" is for template! Put template variables into here
     * @return string
     */
    protected function simple_edit_template($t) {
        global $USER;

        $required = get_string('required');
        $subjectlabeldefault = get_string('subject', 'forumimproved');
        if (!array_key_exists('subjectrequired', $t) || $t['subjectrequired'] === true) {
            $subjectlabeldefault .= " ($required)";
        }

        // Apply some sensible defaults.
        $t += array(
            'postid'             => 0,
            'hidden'             => '',
            'subject'            => '',
            'subjectlabel'       => $subjectlabeldefault,
            'subjectrequired'    => true,
            'subjectplaceholder' => get_string('subjectplaceholder', 'forumimproved'),
            'message'            => '',
            'messagelabel'       => get_string('message', 'forumimproved')." ($required)",
            'messageplaceholder' => get_string('messageplaceholder', 'forumimproved'),
            'attachmentlabel'    => get_string('attachment', 'forumimproved'),
            'submitlabel'        => get_string('submit', 'forumimproved'),
            'cancellabel'        => get_string('cancel'),
            'userpicture'        => $this->output->user_picture($USER, array('link' => false, 'size' => 100)),
            'extrahtml'          => '',
            'advancedlabel'      => get_string('useadvancededitor', 'forumimproved'),
            'thresholdwarning'   => '' ,
            'thresholdblocked'   => '' ,
        );


        $t            = (object) $t;
        $legend       = s($t->legend);
        $subject      = s($t->subject);
        $hidden       = html_writer::input_hidden_params($t->actionurl);
        $actionurl    = $t->actionurl->out_omit_querystring();
        $advancedurl  = s($t->advancedurl);
        $messagelabel = s($t->messagelabel);
        $files        = '';
        $attachments  = new \mod_forumimproved\attachments($t->forum, $t->context);
        $canattach    = $attachments->attachments_allowed();

        $subjectrequired = '';
        if ($t->subjectrequired) {
            $subjectrequired = 'required="required"';
        }
        if (!empty($t->postid) && $canattach) {
            foreach ($attachments->get_attachments($t->postid) as $file) {
                $checkbox = html_writer::checkbox('deleteattachment[]', $file->get_filename(), false).
                    get_string('deleteattachmentx', 'forumimproved', $file->get_filename());

                $files .= html_writer::tag('label', $checkbox);
            }
            $files = html_writer::tag('legend', get_string('deleteattachments', 'forumimproved'), array('class' => 'accesshide')).$files;
            $files = html_writer::tag('fieldset', $files);
        }
        if ($canattach) {
            $files .= <<<HTML
                <label>
                    <span class="accesshide">$t->attachmentlabel</span>
                    <input type="file" name="attachment[]" multiple="multiple" />
                </label>
HTML;
        }

        return <<<HTML
<div class="forumimproved-reply-wrapper$t->thresholdblocked">
    <form method="post" role="region" aria-label="$t->legend" class="forumimproved-form $t->class" action="$actionurl" autocomplete="off">
        <fieldset>
            <legend>$t->legend</legend>
            $t->thresholdwarning
            <div class="forumimproved-validation-errors" role="alert"></div>
            <div class="forumimproved-post-figure">
                $t->userpicture
            </div>
            <div class="forumimproved-post-body">
                <label>
                    <span class="accesshide">$t->subjectlabel</span>
                    <input type="text" placeholder="$t->subjectplaceholder" name="subject" class="form-control" $subjectrequired spellcheck="true" value="$subject" maxlength="255" />
                </label>
                <textarea name="message" class="hidden"></textarea>
                <div data-placeholder="$t->messageplaceholder" aria-label="$messagelabel" contenteditable="true" required="required" spellcheck="true" role="textbox" aria-multiline="true" class="forumimproved-textarea">$t->message</div>

                $files

                $t->extrahtml
                $hidden
                <button type="submit">$t->submitlabel</button>
                <a href="#" class="forumimproved-cancel disable-router">$t->cancellabel</a>
                <a href="$advancedurl" aria-pressed="false" class="forumimproved-use-advanced disable-router">$t->advancedlabel</a>
            </div>
        </fieldset>
    </form>
</div>
HTML;

    }

    public function article_js($context = null) {
        if (!$context instanceof \context) {
            $contextid = $this->page->context->id;
        } else {
            $contextid = $context->id;
        }
        // For some reason, I need to require core_rating manually...
        $this->page->requires->js_module('core_rating');
        $this->page->requires->yui_module(
            'moodle-mod_forumimproved-article',
            'M.mod_forumimproved.init_article',
            array(array(
                'contextId' => $contextid,
            ))
        );
        $this->page->requires->strings_for_js(array(
            'replytox',
            'xdiscussions',
            'deletesure',
        ), 'mod_forumimproved');
        $this->page->requires->string_for_js('changesmadereallygoaway', 'moodle');
    }

    protected function get_post_user_url($cm, $postuser) {
        if (!$postuser->user_picture->link) {
            return null;
        }
        return new moodle_url('/user/profile.php', array('id' => $postuser->id));
    }

    protected function notification_area() {
        return "<div class='forumimproved-notification'aria-hidden='true' aria-live='assertive'></div>";
    }

    /**
     * @param stdClass $post
     * @param stdClass $discussion
     * @param stdClass $cm
     * @param bool $canreply
     * @return array
     * @throws coding_exception
     * @author Mark Nielsen
     */
    public function post_get_commands($post, $discussion, $cm, $canreply) {
        global $CFG, $USER;

        $discussionlink = new moodle_url('/mod/forumimproved/discuss.php', array('d' => $post->discussion));
        $ownpost        = (isloggedin() and $post->userid == $USER->id);
        $commands       = array();

        if (!property_exists($post, 'privatereply')) {
            throw new coding_exception('Must set post\'s privatereply property!');
        }

        $forum = forumimproved_get_cm_forum($cm);

        $postuser   = forumimproved_extract_postuser($post, $forum, context_module::instance($cm->id));

        if ($canreply and empty($post->privatereply)) {
            $replytitle = get_string('replybuttontitle', 'forumimproved', strip_tags($postuser->fullname));
            $commands['reply'] = html_writer::link(
                new moodle_url('/mod/forumimproved/post.php', array('reply' => $post->id)),
                get_string('reply', 'forumimproved'),
                array(
                    'title' => $replytitle,
                    'class' => 'forumimproved-reply-link btn btn-default',
                )
            );
        }

        // here, the usage of $canreply is a hack  avoid to add a "$canvote" on prototype of the function
        $canvote = $canreply && $forum->enable_vote;
        $isInTime = (time() >= $forum->votetimestart && time() <= $forum->votetimestop) || ($forum->votetimestart == 0 && $forum->votetimestop == 0);

        if ($canvote and $isInTime and !$ownpost) {
            $votetitle = get_string('votebuttontitle', 'forumimproved', strip_tags($postuser->fullname));
            $classes = 'forumimproved-vote-link btn btn-default';

            if (forumimproved_has_vote($post->id, $USER->id))
                $classes .= ' active';

            $commands['vote'] = html_writer::link(
                new moodle_url('/mod/forumimproved/post.php', array(
                    'vote' => $post->id)
                    // TODO CSRF !!!
                ),
                get_string('vote', 'forumimproved'),
                array(
                    'title' => $votetitle,
                    'class' => $classes
                )
            );
        }

        $canSeeVoter = $canvote;
        $onSchedule = true;

        if ( $forum->vote_display_name && !has_capability('mod/forumimproved:viewwhovote', context_module::instance($cm->id)))
            $onSchedule = false;

        if ( !$forum->vote_display_name && !has_capability('mod/forumimproved:viewwhovote_annonymousvote', context_module::instance($cm->id)))
            $onSchedule = false;
        
        
        $spanClass = 'forumimproved-show-voters-link';
        $spanContent = get_string('countvote', 'forumimproved', html_writer::span($post->votecount, 'forumimproved-votes-counter'));

        if ($canSeeVoter && $onSchedule) {
            $commands['countVote'] = html_writer::link(
                new moodle_url('/mod/forumimproved/whovote.php', array(
                    'postid' => $post->id,
                    'contextid' => context_module::instance($cm->id)->id
                )),
                $spanContent,
                array(
                    'class' => $spanClass
                )
            );
        }
        else if ($canSeeVoter) {
            $commands['countVote'] = html_writer::span(
                $spanContent,
                $spanClass
            );
        }

        // Hack for allow to edit news posts those are not displayed yet until they are displayed
        $age = time() - $post->created;
        if (!$post->parent && $forum->type == 'news' && $discussion->timestart > time()) {
            $age = 0;
        }
        if (($ownpost && $age < $CFG->maxeditingtime) || has_capability('mod/forumimproved:editanypost', context_module::instance($cm->id))) {
            $commands['edit'] = html_writer::link(
                new moodle_url('/mod/forumimproved/post.php', array('edit' => $post->id)),
                get_string('edit', 'forumimproved')
            );
        }

        if (($ownpost && $age < $CFG->maxeditingtime && has_capability('mod/forumimproved:deleteownpost', context_module::instance($cm->id))) || has_capability('mod/forumimproved:deleteanypost', context_module::instance($cm->id))) {
            $commands['delete'] = html_writer::link(
                new moodle_url('/mod/forumimproved/post.php', array('delete' => $post->id)),
                get_string('delete', 'forumimproved')
            );
        }

        if (has_capability('mod/forumimproved:splitdiscussions', context_module::instance($cm->id))
                && $post->parent
                && !$post->privatereply
                && $forum->type != 'single') {
            $commands['split'] = html_writer::link(
                new moodle_url('/mod/forumimproved/post.php', array('prune' => $post->id)),
                get_string('prune', 'forumimproved'),
                array('title' => get_string('pruneheading', 'forumimproved'))
            );
        }


        if ($CFG->enableportfolios && empty($forum->anonymous) && (has_capability('mod/forumimproved:exportpost', context_module::instance($cm->id)) || ($ownpost && has_capability('mod/forumimproved:exportownpost', context_module::instance($cm->id))))) {
            require_once($CFG->libdir.'/portfoliolib.php');
            $button = new portfolio_add_button();
            $button->set_callback_options('forumimproved_portfolio_caller', array('postid' => $post->id), 'mod_forumimproved');
            list($attachments, $attachedimages) = forumimproved_print_attachments($post, $cm, 'separateimages');
            if (empty($attachments)) {
                $button->set_formats(PORTFOLIO_FORMAT_PLAINHTML);
            } else {
                $button->set_formats(PORTFOLIO_FORMAT_RICHHTML);
            }
            $porfoliohtml = $button->to_html(PORTFOLIO_ADD_TEXT_LINK);
            if (!empty($porfoliohtml)) {
                $commands['portfolio'] = $porfoliohtml;
            }
        }

        $rating = $this->post_rating($post);
        if (!empty($rating)) {
            $commands['rating'] = $rating;
        }

        return $commands;
    }



    public function advanced_editor(){
        // Only output editor if preferred editor is Atto - tiny mce not supported yet.
        editors_head_setup();
        $editor = editors_get_preferred_editor(FORMAT_HTML);
        if (get_class($editor) == 'atto_texteditor'){
            $editor->use_editor('hiddenadvancededitor');
            return '<div id="hiddenadvancededitorcont"><textarea style="display:none" id="hiddenadvancededitor"></textarea></div>';
        }
        return '';
     }

    /**
     * Previous and next discussion navigation.
     *
     * @param stdClass|false $prevdiscussion
     * @param stdClass|false $nextdiscussion
     */
    public function discussion_navigation($prevdiscussion, $nextdiscussion) {
        global $PAGE;
        $output = '';
        $prevlink = '';
        $nextlink = '';

        if ($prevdiscussion) {
            $prevurl = new moodle_url($PAGE->URL);
            $prevurl->param('d', $prevdiscussion->id);
            $prevlink = '<div class="navigateprevious">'.
                '<div>'.get_string('previousdiscussion', 'forumimproved').'</div>'.
                '<div><a href="'.$prevurl->out().'">'.format_string($prevdiscussion->name).'</a></div>'.
                '</div>';
        }
        if ($nextdiscussion) {
            $nexturl = new moodle_url($PAGE->URL);
            $nexturl->param('d', $nextdiscussion->id);
            $nextlink = '<div class="navigatenext">'.
                '<div>'.get_string('nextdiscussion', 'forumimproved').'</div>'.
                '<div><a href="'.$nexturl->out().'">'.format_string($nextdiscussion->name).'</a></div>'.
                '</div>';
        }

        if ($prevlink !=='' || $nextlink !=='') {
            $output .= '<div class="discussnavigation">';
            $output .= $prevlink;
            $output .= $nextlink;
            $output .= '</div>';
        }
        return $output;
    }

    /**
     * SVG icon sprite
     *
     * @return string
     */
     //fill="#FFFFFF"
    public function svg_sprite() {
        return '<svg style="display:none" x="0px" y="0px"
             viewBox="0 0 100 100" enable-background="new 0 0 100 100">
        <g id="substantive">
            <polygon points="49.9,3.1 65,33.8 99,38.6 74.4,62.6 80.2,96.3 49.9,80.4 19.7,96.3 25.4,62.6
            0.9,38.6 34.8,33.8 "/>
        </g>
        <g id="bookmark">
            <polygon points="88.7,93.2 50.7,58.6 12.4,93.2 12.4,7.8 88.7,7.8 "/>
        </g>
        <g id="subscribe">
	       <polygon  enable-background="new    " points="96.7,84.3 3.5,84.3 3.5,14.8 50.1,49.6 96.7,14.8 	"/>
           <polygon  points="3.5,9.8 96.7,9.8 50.2,44.5 	"/>
        </g>
        </svg>';
    }
}
