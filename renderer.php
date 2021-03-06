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
 * @package   mod_forumplusone
 * @copyright 2009 Sam Hemelryk
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU GPL v2 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 */

require_once(__DIR__.'/lib/discussion/subscribe.php');

/**
 * A custom renderer class that extends the plugin_renderer_base and
 * is used by the forum module.
 *
 * @package   mod_forumplusone
 * @copyright 2009 Sam Hemelryk
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU GPL v2 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 **/
class mod_forumplusone_renderer extends plugin_renderer_base {

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

        $config = get_config('forumplusone');
        $mode    = optional_param('mode', 0, PARAM_INT); // Display mode (for single forum)
        $page    = optional_param('page', 0, PARAM_INT); // which page to show
        $forumicon = "<img src='".$OUTPUT->pix_url('icon', 'forumplusone')."' alt='' class='iconlarge activityicon'/> ";
        echo '<div id="forumplusone-header"><h2>'.$forumicon.format_string($forum->name).'</h2>';
        if (!empty($forum->intro)) {
            echo '<div class="forumplusone_introduction">'.format_module_intro('forumplusone', $forum, $cm->id).'</div>';
        }
        echo "</div>";

        // Update activity group mode changes here.
        groups_get_activity_group($cm, true);

        $dsort = forumplusone_lib_discussion_sort::get_from_session($forum, $context);
        $dsort->set_key(optional_param('dsortkey', $dsort->get_key(), PARAM_ALPHA));
        forumplusone_lib_discussion_sort::set_to_session($dsort);


        if (!empty($forum->blockafter) && !empty($forum->blockperiod)) {
            $a = new stdClass();
            $a->blockafter = $forum->blockafter;
            $a->blockperiod = get_string('secondstotime'.$forum->blockperiod);
            echo $OUTPUT->notification(get_string('thisforumisthrottled', 'forumplusone', $a));
        }

        if ($forum->type == 'qanda' && !has_capability('moodle/course:manageactivities', $context)) {
            echo $OUTPUT->notification(get_string('qandanotify','forumplusone'));
        }

        switch ($forum->type) {
            case 'eachuser':
                if (forumplusone_user_can_post_discussion($forum, null, -1, $cm)) {
                    echo '<p class="mdl-align">';
                    print_string("allowsdiscussions", "forumplusone");
                    echo '</p>';
                }
            // Fall through to following cases.
            case 'blog':
            default:
                forumplusone_print_latest_discussions($course, $forum, -1, $dsort->get_sort_sql(), -1, -1, $page, $config->manydiscussions, $cm, has_capability('mod/forumplusone:viewhiddendiscussion', $context));
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

        require_once($CFG->dirroot.'/mod/forumplusone/lib.php');
        require_once($CFG->libdir.'/completionlib.php');
        require_once($CFG->libdir.'/accesslib.php');

        $output = '';

        $modinfo = get_fast_modinfo($forum->course);
        $forums = $modinfo->get_instances_of('forumplusone');
        if (!isset($forums[$forum->id])) {
            print_error('invalidcoursemodule');
        }
        $cm = $forums[$forum->id];

        $id          = $cm->id;      // Forum instance id (id in course modules table)
        $f           = $forum->id;        // Forum ID

        $config = get_config('forumplusone');

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
            rss_add_http_header($context, 'mod_forumplusone', $forum, $rsstitle);
        }

        // Mark viewed if required
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm);

        /// Some capability checks.
        if (empty($cm->visible) and !has_capability('moodle/course:viewhiddenactivities', $context)) {
            notice(get_string("activityiscurrentlyhidden"));
        }

        if (!has_capability('mod/forumplusone:viewdiscussion', $context)) {
            notice(get_string('noviewdiscussionspermission', 'forumplusone'));
        }

        $params = array(
            'context' => $context,
            'objectid' => $forum->id
        );
        $event = \mod_forumplusone\event\course_module_viewed::create($params);
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('forumplusone', $forum);
        $event->trigger();

        if (!defined(AJAX_SCRIPT) || !AJAX_SCRIPT) {
            // Return here if we post or set subscription etc (but not if we are calling this via ajax).
            $SESSION->fromdiscussion = qualified_me();
        }

        $PAGE->requires->js_init_call('M.mod_forumplusone.init', null, false, $this->get_js_module());
        $output .= $this->svg_sprite();
        $this->view($course, $cm, $forum, $context);

        $url = new \moodle_url('/mod/forumplusone/index.php', ['id' => $course->id]);
        $manageforumsubscriptions = get_string('manageforumsubscriptions', 'mod_forumplusone');
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

        $output = '<div class="forumplusone-new-discussion-target"></div>';
        foreach ($discussions as $discussionpost) {
            list($discussion, $post) = $discussionpost;
            $output .= $this->discussion($cm, $discussion, $post, false);
        }


        // TODO - this is confusing code
        return $this->notification_area().
            $this->output->container('', 'forumplusone-add-discussion-target').
            html_writer::tag('section', $output, array('role' => 'region', 'aria-label' => get_string('discussions', 'forumplusone'), 'class' => 'forumplusone-threads-wrapper')).
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

        $forum = forumplusone_get_cm_forum($cm);
        $postuser = forumplusone_extract_postuser($post, $forum, context_module::instance($cm->id));
        $postuser->user_picture->size = 100;

        $course = forumplusone_get_cm_course($cm);
        if (is_null($canreply)) {
            $canreply = forumplusone_user_can_post($forum, $discussion, null, $cm, $course, context_module::instance($cm->id));
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
        $format = get_string('articledateformat', 'forumplusone');

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
        $data->name     = format_string($discussion->name);
        $data->message  = $this->post_message($post, $cm);
        $data->created  = userdate($post->created, $format);
        $data->datetime = date(DATE_W3C, usertime($post->created));
        $data->modified = userdate($discussion->timemodified, $format);
        $data->replies  = $discussion->replies;
        $data->replyavatars = array();
        if ($data->replies > 0) {
            // Get actual replies
            $fields = user_picture::fields('u');
            $replyusers = $DB->get_records_sql("SELECT DISTINCT $fields FROM {forumplusone_posts} hp JOIN {user} u ON hp.userid = u.id WHERE hp.discussion = ? AND hp.privatereply = 0 ORDER BY hp.modified DESC", array($discussion->id));
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
        $data->viewurl    = new moodle_url('/mod/forumplusone/discuss.php', array('d' => $discussion->id));
        $data->tools      = implode(' ', $this->post_get_commands($post, $discussion, $cm, $canreply));
        $data->postflags  = implode(' ',$this->post_get_flags($post, $cm, $discussion->id));
        $data->subscribe  = '';
        $data->posts      = '';
        $data->fullthread = $fullthread;
        $data->revealed   = false;
        $data->rawcreated = $post->created;
        if (!empty($discussion->lastpostcreationdate))
            $data->rawlastpost = $discussion->lastpostcreationdate;

        if ($forum->anonymous
                && $postuser->id === $USER->id
                && $post->reveal) {
            $data->revealed         = true;
        }

        if ($fullthread && $canreply) {
            $data->replyform = html_writer::tag(
                'div', $this->simple_edit_post($cm, false, $post->id), array('class' => 'forumplusone-footer-reply')
            );
        } else {
            $data->replyform = '';
        }

        if ($fullthread) {
            $data->posts = $this->posts($cm, $discussion, $posts, $canreply);
        }

        $subscribe = new forumplusone_lib_discussion_subscribe($forum, context_module::instance($cm->id));
        $data->subscribe = $this->discussion_subscribe_link($cm, $discussion, $subscribe) ;

        return $this->discussion_template($data, $forum, $cm);
    }

    public function article_assets($cm) {
        $context = context_module::instance($cm->id);
        $forum = forumplusone_get_cm_forum($cm);
        $this->article_js($context);
        $output = html_writer::tag(
            'script',
            $this->simple_edit_post($cm),
            array('type' => 'text/template', 'id' => 'forumplusone-reply-template')
        );
        $output .= html_writer::tag(
            'script',
            $this->simple_edit_discussion($cm),
            array('type' => 'text/template', 'id' => 'forumplusone-discussion-form-template')
        );


        $config = get_config('forumplusone');
        if (has_capability('mod/forumplusone:change_state_discussion', $context) && $forum->enable_states_disc) {
            $output .= html_writer::tag(
                'script',
                '',
                array('type' => 'application/javascript', 'src' => 'js/changeDiscussionState.min.js')
            );
        }

        if (has_capability('mod/forumplusone:live_reload', $context) && !empty($config->livereloadrate) && $config->livereloadrate != 0 && $forum->enable_refresh) {
            // LiveReload
            $output .= html_writer::tag( // variables
                'script',
                $this->getJSVarsLiveReloading(),
                array('type' => 'application/javascript')
            );
            $output .= html_writer::tag( // script
                'script',
                '',
                array('type' => 'application/javascript', 'src' => 'js/liveReload.min.js')
            );
        }

        $output .= html_writer::tag(
            'script',
            '',
            array('type' => 'application/javascript', 'src' => 'js/bootstrap-tooltip.min.js')
        );

        $output .= html_writer::tag(
            'script',
            '',
            array('type' => 'application/javascript', 'src' => 'js/collapseReplies.min.js')
        );

        $output .= html_writer::tag( // variables
            'script',
            $this->getJSVarsShowVotes(),
            array('type' => 'application/javascript')
        );
        $output .= html_writer::tag(
            'script',
            '',
            array('type' => 'application/javascript', 'src' => 'js/seevoters.min.js')
        );

        // enable colors on likes
        {
            $css = <<<CSS
.forumplusone-tools a {
    border-bottom-color: {$config->votesColor};
}
.forumplusone-tools a.active {
    color: {$config->votesColor};
}
CSS;
            $output .= html_writer::tag(
                'style',
                $css
            );
        }

        return $output;
    }

    /**
     * @return a JS string with variables usefull for the liveReloading JS module
     */
    public function getJSVarsLiveReloading() {
        global $CFG;

        $config = get_config('forumplusone');

        $json['intervalReload'] = $config->livereloadrate;
        $json['urlChange'] = $CFG->wwwroot . '/mod/forumplusone/route.php?action=reload&contextid=' . $this->page->context->id;
        $json['msgDel'] = get_string('deleteddiscussion', 'forumplusone');

        $jsonString = json_encode($json);
        return <<<EOS
window.liveReload = $jsonString;
EOS;
    }

    /**
     * @return a JS string with variables usefull for the JS module to show the voters
     */
    public function getJSVarsShowVotes() {
        $json['votersPanelTitle'] = get_string('allvoteforitem', 'forumplusone');
        $json['tableTitleName'] = get_string('username');
        $json['tableTitleDatetime'] = get_string('date');
        $json['thereNoVoteHere'] = get_string('novotes', 'forumplusone');

        $jsonString = json_encode($json);
        return <<<EOS
window.jQueryStrings = $jsonString;
EOS;
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
     * @return string
     */
    public function post($cm, $discussion, $post, $canreply = false, $parent = null, $commands = array(), $search = '') {
        global $USER, $CFG, $DB;

        $forum = forumplusone_get_cm_forum($cm);
        if (!forumplusone_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            // Return a message about why you cannot see the post
            return "<div class='forumplusone-post-content-hidden'>".get_string('forumbodyhidden','forumplusone')."</div>";
        }
        if ($commands === false){
            $commands = array();
        } else if (empty($commands)) {
            $commands = $this->post_get_commands($post, $discussion, $cm, $canreply, false);
        } else if (!is_array($commands)){
            throw new coding_exception('$commands must be false, empty or populated array');
        }
        $postuser = forumplusone_extract_postuser($post, $forum, context_module::instance($cm->id));
        $postuser->user_picture->size = 100;

        // $post->breadcrumb comes from search btw.
        $data                 = new stdClass;
        $data->id             = $post->id;
        $data->discussionid   = $discussion->id;
        $data->fullname       = $postuser->fullname;
        $data->message        = $this->post_message($post, $cm, $search);
        $data->created        = userdate($post->created, get_string('articledateformat', 'forumplusone'));
        $data->rawcreated     = $post->created;
        $data->datetime       = date(DATE_W3C, usertime($post->created));
        $data->privatereply   = $post->privatereply;
        $data->imagesrc       = $postuser->user_picture->get_url($this->page)->out();
        $data->userurl        = $this->get_post_user_url($cm, $postuser);
        $data->unread         = empty($post->postread) ? true : false;
        $data->permalink      = new moodle_url('/mod/forumplusone/discuss.php#p'.$post->id, array('d' => $discussion->id));
        $data->isreply        = false;
        $data->tools          = implode(' ', $commands);
        $data->postflags      = implode(' ',$this->post_get_flags($post, $cm, $discussion->id, false));
        $data->revealed       = false;
        $data->isFirstPost    = empty($post->isFirstPost) ? false : $post->isFirstPost;

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
            $data->replycount = forumplusone_xreplies($post->replycount);
        }

        // Mark post as read.
        if ($data->unread) {
            forumplusone_mark_post_read($USER->id, $post, $forum->id);
        }

        if (isset($post->replies))
            $data->replies = $post->replies;
        else
            $data->replies = null;

        if (isset($post->subscribe))
            $data->subscribe = $post->subscribe;
        else
            $data->subscribe = null;

        if (isset($post->stateForm))
            $data->stateForm = $post->stateForm;
        else
            $data->stateForm = null;

        if (isset($post->iconState))
            $data->iconState = $post->iconState;
        else
            $data->iconState = null;

        if (isset($post->threadtitle))
            $data->threadtitle = $post->threadtitle;
        else
            $data->threadtitle = null;



        return $this->post_template($data);
    }

    public function discussion_template($d, $forum, $cm) {
        $xreplies = '';
        if(!empty($d->replies)) {
            $xreplies = forumplusone_xreplies($d->replies);
        }
        if (!empty($d->userurl)) {
            $byuser = html_writer::link($d->userurl, $d->fullname);
        } else {
            $byuser = html_writer::tag('span', $d->fullname);
        }
        $byuser = get_string('byx', 'forumplusone', $byuser);     
        $unread = '';
        $unreadclass = '';
        $attrs = '';
        if ($d->unread != '-') {
            $new  = get_string('unread', 'forumplusone');
            $titleNewPost = get_string('unreadposts', 'forumplusone');
            $unread  = "<span class='forumplusone-unreadcount label label-info disable-router' title='$titleNewPost'>$new</span>";
            $attrs   = 'data-isunread="true"';
            $unreadclass = 'forumplusone-post-unread';
        }

        $author = s(strip_tags($d->fullname));
        $group = '';
        if (!empty($d->group)) {
            $group = $d->group;
        }

        $latestpost = '';
        if (!empty($d->replies) && !$d->fullthread) {
            $latestpost = get_string('lastposttimeago', 'forumplusone', forumplusone_absolute_time($d->rawlastpost));
        }


        $popularity = forumplusone_get_count_votes($d->id, $forum->count_vote_mode);

        $popularityText ='';
        if ($popularity > 0) {
            $popularityText = get_string('popularity_text', 'forumplusone', $popularity);

            $onSchedule = true;

            if ( $forum->vote_display_name && !has_capability('mod/forumplusone:viewwhovote', \context_module::instance($cm->id)))
                $onSchedule = false;

            if ( !$forum->vote_display_name && !has_capability('mod/forumplusone:viewwhovote_annonymousvote', \context_module::instance($cm->id)))
                $onSchedule = false;


            if ($onSchedule) {
                $popularityText = html_writer::link(
                    new moodle_url('/mod/forumplusone/whovote.php', array(
                        'postid' => $d->postid,
                        'contextid' => \context_module::instance($cm->id)->id
                    )),
                    $popularityText,
                    array(
                        'class' => 'forumplusone-show-voters-link',
                        'data-toggle' => 'tooltip',
                        'data-placement' => 'top',
                        'title' => get_string('show-voters-link-title', 'forumplusone'),
                    )
                );
            }
        }


        $datecreated = forumplusone_absolute_time($d->rawcreated, array('class' => 'forumplusone-thread-pubdate'));

        $threadtitle = $d->name;
        if (!$d->fullthread) {
            $threadtitle = "<a class='disable-router' href='$d->viewurl'>$threadtitle</a>";
        }
        $options = get_string('options', 'forumplusone');
        $threadmeta  =
            "<div class='forumplusone-thread-meta'>
                <div>$d->subscribe $d->postflags</div>
                <p><small>&nbsp;{$xreplies}</small><br>
                <small>&nbsp;$latestpost</small><br>
                <small>&nbsp;$unread</small><br>
                <small>&nbsp;$popularityText</small></p>
            </div>";

        if ($d->fullthread) {
            $tools = '<div role="region" class="forumplusone-tools forumplusone-thread-tools" aria-label="'.$options.'">'.$d->tools.'</div>';
            $blogreplies = '';
        } else {
            $blogreplies = forumplusone_xreplies($d->replies);
            $tools = "<a class='disable-router forumplusone-replycount-link' href='$d->viewurl'>$blogreplies</a>";
        }

        $revealed = "";
        if ($d->revealed) {
            $nonanonymous = get_string('nonanonymous', 'mod_forumplusone');
            $revealed = '<span class="label label-danger">'.$nonanonymous.'</span>';
        }




        $context = \context_module::instance($cm->id);

        $classStateDiscussion = '';

        $d->stateForm = "";
        $d->iconState = "";
        if ($forum->enable_states_disc) {
            $titleOpenDiscussion = get_string('title_open_discussion', 'forumplusone');
            $titleCloseDiscussion = get_string('title_close_discussion', 'forumplusone');
            $titleHideDiscussion = get_string('title_hide_discussion', 'forumplusone');
            $titleIsClosedDiscussion = get_string('title_is_closed_discussion', 'forumplusone');
            $titleIsHiddenDiscussion = get_string('title_is_hidden_discussion', 'forumplusone');

            if (forumplusone_is_discussion_closed($forum, $d)) {
                $classStateDiscussion = 'topic-closed';
            }
            elseif (forumplusone_is_discussion_hidden($forum, $d)) {
                $classStateDiscussion = 'topic-hidden';
            }

            if (has_capability('mod/forumplusone:change_state_discussion', $context)) {
                    $checkAttrClosed = '';
                    $checkAttrHidden = '';
                    $checkAttrOpen = '';

                    if (forumplusone_is_discussion_closed($forum, $d)) {
                        $checkAttrClosed = 'checked';
                    }
                    elseif (forumplusone_is_discussion_hidden($forum, $d)) {
                        $checkAttrHidden = 'checked';
                    }
                    else {
                        $checkAttrOpen = 'checked';
                    }

                    $contextid = $this->page->context->id;

                    $d->stateForm = "
<form class='stateChanger' action='/mod/forumplusone/post.php' method='get'>
    <div class='selectContainer'>
        <div class='select' tabindex='-1'>
            <input class='selectopt' name='state' value='" . FORUMPLUSONE_DISCUSSION_STATE_OPEN . "' type='radio' {$checkAttrOpen} id='openState{$d->id}'>
            <label for='openState{$d->id}' class='option' data-toggle='tooltip' data-placement='right' title='{$titleOpenDiscussion}'><svg class='svg-icon'><use xlink:href='#icon-open'>
                <desc>{$titleOpenDiscussion}</desc>
            </use></svg></label>
            <input class='selectopt' name='state' value='" . FORUMPLUSONE_DISCUSSION_STATE_CLOSE . "' type='radio' {$checkAttrClosed} id='closedState{$d->id}'>
        <label for='closedState{$d->id}' class='option' data-toggle='tooltip' data-placement='right' title='{$titleCloseDiscussion}'><svg class='svg-icon'><use xlink:href='#icon-close'>
                <desc>{$titleCloseDiscussion}</desc>
            </use></svg></label>
            <input class='selectopt' name='state' value='" . FORUMPLUSONE_DISCUSSION_STATE_HIDDEN . "' type='radio' {$checkAttrHidden} id='hiddenState{$d->id}'>
            <label for='hiddenState{$d->id}' class='option' data-toggle='tooltip' data-placement='right' title='{$titleHideDiscussion}'><svg class='svg-icon'><use xlink:href='#icon-hide'>
                <desc>{$titleHideDiscussion}</desc>
            </use></svg></label>
        </div>
    </div>
    <input type='hidden' name='contextid' value='{$contextid}'>
    <input type='hidden' name='d' value='{$d->id}'>
    <input type='hidden' name='fullthread' value='{$d->fullthread}'>
    <input type='submit' value='Update'>
</form>";
            }
            else {
                if (forumplusone_is_discussion_closed($forum, $d)) {
                    $d->iconState = "<svg class='svg-icon' data-toggle='tooltip' data-placement='right' title='{$titleIsClosedDiscussion}'><use xlink:href='#icon-close'>
                                        <desc>{$titleIsClosedDiscussion}</desc>
                                    </use></svg>";
                }
                elseif (forumplusone_is_discussion_hidden($forum, $d)) {
                    $d->iconState = "<svg class='svg-icon' data-toggle='tooltip' data-placement='right' title='{$titleIsHiddenDiscussion}'><use xlink:href='#icon-hide'>
                                        <desc>{$titleIsHiddenDiscussion}</desc>
                                    </use></svg>";
                }
            }
        }



        if ($d->fullthread) {
            $d->threadtitle = $threadtitle;
            $d->isFirstPost = true;
            $firstPost = $this->post_template($d);

            return <<<HTML
<article id="p{$d->postid}" class="forumplusone-thread forumplusone-post-target clearfix {$classStateDiscussion}"
    data-discussionid="$d->id" data-postid="$d->postid" data-author="$author" data-isdiscussion="true" $attrs>

    $firstPost

    <div id="forumplusone-thread-{$d->id}" class="forumplusone-thread-body">
        <!-- specific to blog style -->
        $d->posts
        $d->replyform
    </div>
</article>
HTML;
        }



        return <<<HTML
<article id="p{$d->postid}" class="forumplusone-thread forumplusone-post-target clearfix {$classStateDiscussion}"
    data-discussionid="$d->id" data-postid="$d->postid" data-author="$author" data-isdiscussion="true" $attrs>
    <header id="h{$d->postid}" class="clearfix $unreadclass">
        $threadmeta

        <div class="forumplusone-tread-main">
            <div class="clearfixLeft">
                $d->stateForm
                <h4 id="thread-title-{$d->id}">$d->iconState $threadtitle</h4>
            </div>
            <p>$byuser $group $revealed &mdash; $datecreated</p>
        </div>
        $tools
    </header>
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
                $forum = forumplusone_get_cm_forum($cm);
                forumplusone_mark_post_read($USER->id, $parent, $forum->id);
            }
        }
        $output = '';
        if (!empty($count)) {
            return "<div class='forumplusone-thread-replies'><ol class='forumplusone-thread-replies-list'>".$items."</ol></div>";
        }
        return '';
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
     * @return string
     */
    public function post_walker($cm, $discussion, $posts, $parent, $canreply, &$count, $container = true) {
        $output = '';
        foreach ($posts as $post) {
            if ($post->parent != $parent->id) {
                continue;
            }
            $html = $this->post($cm, $discussion, $post, $canreply, $parent, array());
            if (!empty($html)) {
                $count++;

                if ($container) {
                    $output .= "<li class='forumplusone-post'>";
                }

                $output .= $html;

                if ($container) {
                    $output .= '<ol class="forumplusone-thread-replies-list">';
                }

                if (!empty($post->children)) {
                    $output .= $this->post_walker($cm, $discussion, $posts, $post, $canreply, $count);
                }

                if ($container) {
                    $output .= '</ol>';
                    $output .= "</li>";
                }
            }
        }
        return $output;
    }

    /**
     * Return html for individual post
     *
     * 4 use cases:
     *  1. Standard post
     *  2. Reply to user
     *  3. Private reply to user
     *  4. Start of a discussion
     *
     * @param object $p
     * @return string
     */
    public function post_template($p) {
        global $PAGE;

        $isFirstPost = empty($p->isFirstPost) ? false : $p->isFirstPost;

        $byuser = $p->fullname;
        if (!empty($p->userurl)) {
            $byuser = html_writer::link($p->userurl, $p->fullname);
        }

        $author = s(strip_tags($p->fullname));
        $unread = '';
        $unreadclass = '';
        if ($p->unread && !$isFirstPost) {
            $unread = "<span class='forumplusone-unreadcount'>".get_string('unread', 'forumplusone')."</span>";
            $unreadclass = "forumplusone-post-unread";
        }
        $options = get_string('options', 'forumplusone');
        $datecreated = forumplusone_absolute_time($p->rawcreated, array('class' => 'forumplusone-post-pubdate'));


        $postreplies = '';
        if (!$isFirstPost && $p->replycount) {
            $postreplies = "<div class='post-reply-count accesshide'>$p->replycount</div>";
        }

        $postCountReplies = '<p class="forumplusone-count-replies">';
        if ($isFirstPost) {
            $classSpan = $p->replies ? '' : 'hidden';
            $postCountReplies .= '<span class="counterReplies ' . $classSpan . '">' . forumplusone_xreplies($p->replies) . '</span>';
        }
        $postCountReplies .= ' ' . html_writer::tag(
            'svg',
            '<use xlink:href="#collapse"/>',
            array(
                'class' => 'collapse-icon svg-icon inlineJs ' . (!($isFirstPost && $p->replies) && !(!$isFirstPost && ((int)$p->replycount) > 0 ) ?  'hidden' : ''),
                'data-toggle' => 'tooltip',
                'data-placement' => 'top',
                'data-title-collapse' => get_string('title-replies-collapse', 'forumplusone'),
                'title' => get_string('title-replies-collapse', 'forumplusone'),
                'data-title-uncollapse' => get_string('title-replies-uncollapse', 'forumplusone')
            )
        );
        $postCountReplies .= '</p>';



        $newwindow = '';
        if ($PAGE->pagetype === 'local-joulegrader-view') {
            $newwindow = ' target="_blank"';
        }

        $revealed = "";
        if ($p->revealed) {
            $nonanonymous = get_string('nonanonymous', 'mod_forumplusone');
            $revealed = '<span class="label label-danger">'.$nonanonymous.'</span>';
        }

        $group = '';
        if (!empty($p->group)) {
            $group = $p->group;
        }

        $subscribe = '';
        if ($isFirstPost) {
            $subscribe = $p->subscribe;
        }

        if ($isFirstPost) {

            $html = <<<HTML
<div class='forumplusone-post-wrapper forumplusone-post-target clearfix firstpost {$unreadclass}' data-discussionid='{$p->id}' data-author='{$author}' data-ispost='true'>

    <p class="forumplusone-thread-flags">{$subscribe} {$p->postflags}</p>

    <header class="topic-header clearfix">
        {$p->stateForm}
        <h3 id="thread-title-{$p->id}">{$p->iconState} {$p->threadtitle}</h3>
    </header>
HTML;

        }
        else {
            $html = "<div class='forumplusone-post-wrapper forumplusone-post-target clearfix $unreadclass' id='p$p->id' data-postid='$p->id' data-discussionid='$p->discussionid' data-author='$author' data-ispost='true'>
    <p class='forumplusone-thread-flags'>$subscribe $p->postflags</p>";
        }


    return $html . <<<HTML
    <img class="userpicture" src="{$p->imagesrc}" alt="">

    <div class="forumplusone-post-body">
        <p class="forumplusone-thread-meta">$unread $byuser $group $revealed <span class="forumplusone-post-time">$datecreated</span></p>

        <div class="forumplusone-post-content">
            $p->message
        </div>
        <div role="region" class='forumplusone-tools' aria-label='$options'>
            $p->tools
        </div>
        $postCountReplies
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
            $output .= $this->output->heading(get_string("nosubscribers", "forumplusone"));
        } else if (!isset($modinfo->instances['forumplusone'][$forum->id])) {
            $output .= $this->output->heading(get_string("invalidmodule", "error"));
        } else {
            $cm = $modinfo->instances['forumplusone'][$forum->id];
            $canviewemail = in_array('email', get_extra_user_fields(context_module::instance($cm->id)));
            $output .= $this->output->heading(get_string("subscribersto","forumplusone", "'".format_string($entityname)."'"));
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
        $output .= html_writer::tag('p', get_string('forcessubscribe', 'forumplusone'));
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
            'name'      => 'mod_forumplusone',
            'fullpath'  => '/mod/forumplusone/module.js',
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
                array('jsondecodeerror', 'forumplusone'),
                array('ajaxrequesterror', 'forumplusone'),
                array('clicktoexpand', 'forumplusone'),
                array('clicktocollapse', 'forumplusone'),
                array('manualwarning', 'forumplusone'),
                array('subscribeshort', 'forumplusone'),
                array('unsubscribeshort', 'forumplusone'),
                array('useadvancededitor', 'forumplusone'),
                array('hideadvancededitor', 'forumplusone'),
                array('loadingeditor', 'forumplusone'),
                array('toggle:bookmark', 'forumplusone'),
                array('toggle:subscribe', 'forumplusone'),
                array('toggle:substantive', 'forumplusone'),
                array('toggled:bookmark', 'forumplusone'),
                array('toggled:subscribe', 'forumplusone'),
                array('toggled:substantive', 'forumplusone')

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

        if (!has_capability('mod/forumplusone:viewflags', $context)) {
            return array();
        }
        if (!property_exists($post, 'flags')) {
            throw new coding_exception('The post\'s flags property must be set');
        }
        require_once(__DIR__.'/lib/flag.php');

        $flaglib   = new forumplusone_lib_flag();
        $canedit   = has_capability('mod/forumplusone:editanypost', $context);
        $returnurl = $this->return_url($cm->id, $discussion);

        $flaghtml = array();

        $forum = forumplusone_get_cm_forum($cm);
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

            $url = new moodle_url('/mod/forumplusone/route.php', array(
                'contextid' => $context->id,
                'action'    => 'flag',
                'returnurl' => $returnurl,
                'postid'    => $post->id,
                'flag'      => $flag,
                'sesskey'   => sesskey()
            ));

            // Create appropriate area described by.
            $describedby = $reply ? 'thread-title-'.$discussion : 'forumplusone-post-'.$post->id;

            // Create toggle element.
            $flaghtml[$flag] = $this->toggle_element($flag,
                $describedby,
                $url,
                $isflagged,
                $canedit,
                array('class' => 'forumplusone_flag')
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
            $label = get_string('toggled:'.$type, 'forumplusone');
        } else {
            $label = get_string('toggle:'.$type, 'forumplusone');
        }
        if (empty($attributes)) {
            $attributes = array();
        }
        if (!isset($attributes['class'])){
            $attributes['class'] = '';
        }
        $classes = array($attributes['class'], 'forumplusone-toggle forumplusone-toggle-'.$type);
        if ($pressed) {
            $classes[] = 'forumplusone-toggled';
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
     * @param forumplusone_lib_discussion_subscribe $subscribe
     * @return string
     * @author Mark Nielsen / Guy Thomas
     */
    public function discussion_subscribe_link($cm, $discussion, forumplusone_lib_discussion_subscribe $subscribe) {

        if (!$subscribe->can_subscribe()) {
            return;
        }

        $returnurl = $this->return_url($cm->id, $discussion->id);

        $url = new moodle_url('/mod/forumplusone/route.php', array(
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
            array('class' => 'forumplusone_discussion_subscribe')
        );
        return $o;
    }

    /**
     * @param $cm
     * @param forumplusone_lib_discussion_sort $sort
     * @return string
     * @author Mark Nielsen
     */
    public function discussion_sorting($cm, forumplusone_lib_discussion_sort $sort) {

        $url = new moodle_url('/mod/forumplusone/view.php');

        $sortselect = html_writer::select($sort->get_key_options_menu(), 'dsortkey', $sort->get_key(), false, array('class' => ''));
        $sortform = "<form method='get' action='$url' class='forumplusone-discussion-sort'>
                    <legend class='accesshide'>".get_string('sortdiscussions', 'forumplusone')."</legend>
                    <input type='hidden' name='id' value='{$cm->id}'>
                    <label for='dsortkey' class='accesshide'>".get_string('orderdiscussionsby', 'forumplusone')."</label>
                    $sortselect
                    <input type='submit' value='".get_string('sortdiscussionsby', 'forumplusone')."'>
                    </form>";

        return $sortform;
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

        list($attachments, $attachedimages) = forumplusone_print_attachments($post, $cm, 'separateimages');

        $message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', context_module::instance($cm->id)->id, 'mod_forumplusone', 'post', $post->id);

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



        $forum = forumplusone_get_cm_forum($cm);
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

        $config = get_config('forumplusone');

        $forum = forumplusone_get_cm_forum($cm);

        $showonlypreferencebutton = '';
        if (!empty($showonlypreference) and !empty($showonlypreference->button) and !$forum->anonymous) {
            $showonlypreferencebutton = $showonlypreference->button;
        }

        $output    = '';
        $postcount = $discussioncount = $flagcount = 0;
        $flaglib   = new forumplusone_lib_flag();
        if ($posts = forumplusone_get_user_posts($forum->id, $userid, context_module::instance($cm->id))) {
            $discussions = forumplusone_get_user_involved_discussions($forum->id, $userid);
            if (!empty($showonlypreference) and !empty($showonlypreference->preference)) {
                foreach ($discussions as $discussion) {

                    if ($discussion->userid == $userid and array_key_exists($discussion->firstpost, $posts)) {
                        $discussionpost = $posts[$discussion->firstpost];

                        $discussioncount++;
                        if ($flaglib->is_flagged($discussionpost->flags, 'substantive')) {
                            $flagcount++;
                        }
                    } else {
                        if (!$discussionpost = forumplusone_get_post_full($discussion->firstpost)) {
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
                                new moodle_url('/mod/forumplusone/discuss.php', array('d' => $discussion->id), 'p'.$post->id),
                                get_string('postincontext', 'forumplusone'),
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
                            new moodle_url('/mod/forumplusone/discuss.php', array('d' => $post->discussion), 'p'.$post->id),
                            get_string('postincontext', 'forumplusone'),
                            array('target' => '_blank')
                        );
                        $output .= $this->post($cm, $discussions[$post->discussion], $post, false, null, false);
                    }
                }
            }
        }
        if (!empty($postcount) or !empty($discussioncount)) {

            if ($forum->anonymous) {
                $output = html_writer::tag('h3', get_string('thisisanonymous', 'forumplusone'));
            }
            $counts = array(
                get_string('totalpostsanddiscussions', 'forumplusone', ($discussioncount+$postcount)),
                get_string('totaldiscussions', 'forumplusone', $discussioncount),
                get_string('totalreplies', 'forumplusone', $postcount)
            );

            if (!empty($config->showsubstantive)) {
                $counts[] = get_string('totalsubstantive', 'forumplusone', $flagcount);
            }

            if ($grade = forumplusone_get_user_formatted_rating_grade($forum, $userid)) {
                $counts[] = get_string('totalrating', 'forumplusone', $grade);
            }
            $countshtml = '';
            foreach ($counts as $count) {
                $countshtml .= html_writer::tag('div', $count, array('class' => 'forumplusone_count'));
            }
            $output = html_writer::div($countshtml, 'forumplusone_counts').$showonlypreferencebutton.$output;
            $output = html_writer::div($output, 'mod-forumplusone-posts-container article');
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
            $message = get_string('validationerrorx', 'forumplusone', $error->getMessage());
        } else if (count($errors) > 1) {
            $items = array();
            foreach ($errors as $error) {
                $items[] = $error->getMessage();
            }
            $message = get_string('validationerrorsx', 'forumplusone', array(
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
        $forum = forumplusone_get_cm_forum($cm);

        if (!empty($postid)) {
            $params = array('edit' => $postid);
            $legend = get_string('editingpost', 'forumplusone');
            $post = $DB->get_record('forumplusone_posts', ['id' => $postid]);
            if ($post->userid != $USER->id) {
                $user = $DB->get_record('user', ['id' => $post->userid]);
                $user = forumplusone_anonymize_user($user, $forum, $post);
                $data['userpicture'] = $this->output->user_picture($user, array('link' => false, 'size' => 100));
            }
        } else {
            $params  = array('forum' => $cm->instance);
            $legend = get_string('addyourdiscussion', 'forumplusone');
            $thresholdwarning = forumplusone_check_throttling($forum, $cm);
            if (!empty($thresholdwarning)) {
                $message = get_string($thresholdwarning->errorcode, $thresholdwarning->module, $thresholdwarning->additional);
                $data['thresholdwarning'] = $OUTPUT->notification($message);
                if ($thresholdwarning->canpost === false) {
                    $data['thresholdblocked'] = " forumplusone-threshold-blocked ";
                }
            }
        }

        $data += array(
            'itemid'        => 0,
            'groupid'       => 0,
            'messageformat' => FORMAT_HTML,
        );
        $actionurl = new moodle_url('/mod/forumplusone/route.php', array(
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
            if (count($groupdata) > 1 && has_capability('mod/forumplusone:movediscussions', $context)) {
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
                get_string('reveal', 'forumplusone'));
        }
        $data += array(
            'subject'     => true,
            'postid'      => $postid,
            'context'     => $context,
            'forum'       => $forum,
            'actionurl'   => $actionurl,
            'class'       => 'forumplusone-discussion',
            'legend'      => $legend,
            'extrahtml'   => $extrahtml,
            'advancedurl' => new moodle_url('/mod/forumplusone/post.php', $params),
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
        $forum = forumplusone_get_cm_forum($cm);
        $postuser = $USER;
        $ownpost = false;

        if ($isedit) {
            $param  = 'edit';
            $legend = get_string('editingpost', 'forumplusone');
            $post = $DB->get_record('forumplusone_posts', ['id' => $postid]);
            if ($post->userid == $USER->id) {
                $ownpost = true;
            } else {
                $postuser = $DB->get_record('user', ['id' => $post->userid]);
                $postuser = forumplusone_anonymize_user($postuser, $forum, $post);
                $data['userpicture'] = $this->output->user_picture($postuser, array('link' => false, 'size' => 100));
            }
        } else {
            // It is a reply, AKA new post
            $ownpost = true;
            $param  = 'reply';
            $legend = get_string('addareply', 'forumplusone');
            $thresholdwarning = forumplusone_check_throttling($forum, $cm);
            if (!empty($thresholdwarning)) {
                $message = get_string($thresholdwarning->errorcode, $thresholdwarning->module, $thresholdwarning->additional);
                $data['thresholdwarning'] = $OUTPUT->notification($message);
                if ($thresholdwarning->canpost === false) {
                    $data['thresholdblocked'] = " forumplusone-threshold-blocked ";
                }
            }
        }

        $data += array(
            'itemid'        => 0,
            'privatereply'  => 0,
            'reveal'        => 0,
            'messageformat' => FORMAT_HTML,
        );
        $actionurl = new moodle_url('/mod/forumplusone/route.php', array(
            'action'        => ($isedit) ? 'update_post' : 'reply',
            $param          => $postid,
            'sesskey'       => sesskey(),
            'contextid'     => $context->id,
            'itemid'        => $data['itemid'],
            'messageformat' => $data['messageformat'],
        ));

        $extrahtml = '';
        if (has_capability('mod/forumplusone:allowprivate', $context, $postuser)
            && $forum->allowprivatereplies !== '0'
        ) {
            $extrahtml .= html_writer::tag('label', html_writer::checkbox('privatereply', 1, !empty($data['privatereply'])).
                get_string('privatereply', 'forumplusone'));
        }
        if ($forum->anonymous && !$isedit
            || $forum->anonymous && $isedit && $ownpost) {
            $extrahtml .= html_writer::tag('label', html_writer::checkbox('reveal', 1, !empty($data['reveal'])).
                get_string('reveal', 'forumplusone'));
        }
        $data += array(
            'postid'          => ($isedit) ? $postid : 0,
            'context'         => $context,
            'forum'           => $forum,
            'actionurl'       => $actionurl,
            'class'           => 'forumplusone-reply',
            'legend'          => $legend,
            'extrahtml'       => $extrahtml,
            'subjectrequired' => false, # $isedit,
            'advancedurl'     => new moodle_url('/mod/forumplusone/post.php', array($param => $postid)),
        );

        if ($isedit) {
            $data['class'] .= ' forumplusone-edit';
        }

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
        $subjectlabeldefault = get_string('subject', 'forumplusone');
        if (!array_key_exists('subject', $t) || !empty($t['subject'])) {
            $subjectlabeldefault .= " ($required)";
        }

        // Apply some sensible defaults.
        $t += array(
            'postid'             => 0,
            'hidden'             => '',
            'subject'            => '',
            'subjectlabel'       => $subjectlabeldefault,
            'subjectplaceholder' => get_string('subjectplaceholder', 'forumplusone'),
            'message'            => '',
            'messagelabel'       => get_string('message', 'forumplusone')." ($required)",
            'messageplaceholder' => get_string('messageplaceholder', 'forumplusone'),
            'attachmentlabel'    => get_string('attachment', 'forumplusone'),
            'submitlabel'        => get_string('submit', 'forumplusone'),
            'cancellabel'        => get_string('cancel'),
            'userpicture'        => $this->output->user_picture($USER, array('link' => false, 'size' => 100)),
            'extrahtml'          => '',
            'advancedlabel'      => get_string('useadvancededitor', 'forumplusone'),
            'thresholdwarning'   => '' ,
            'thresholdblocked'   => '' ,
        );

        $displaySubject = false;
        if ($t['subject'] === true) {
            $displaySubject = true;
            $t['subject'] = '';
        }

        $t            = (object) $t;
        $legend       = s($t->legend);
        $subject      = s($t->subject);
        $hidden       = html_writer::input_hidden_params($t->actionurl);
        $actionurl    = $t->actionurl->out_omit_querystring();
        $advancedurl  = s($t->advancedurl);
        $messagelabel = s($t->messagelabel);
        $files        = '';
        $attachments  = new \mod_forumplusone\attachments($t->forum, $t->context);
        $canattach    = $attachments->attachments_allowed();

        $subjectField = '';
        if ($displaySubject || !empty($subject)) {
            $subjectField = '
                <label>
                    <span class="accesshide">' . $t->subjectlabel . '</span>
                    <input type="text" placeholder="' . $t->subjectplaceholder . '" name="subject" class="form-control" required="required" spellcheck="true" value="' . $subject . '" maxlength="255" />
                </label>';
        }
        if (!empty($t->postid) && $canattach) {
            foreach ($attachments->get_attachments($t->postid) as $file) {
                $checkbox = html_writer::checkbox('deleteattachment[]', $file->get_filename(), false).
                    get_string('deleteattachmentx', 'forumplusone', $file->get_filename());

                $files .= html_writer::tag('label', $checkbox);
            }
            $files = html_writer::tag('legend', get_string('deleteattachments', 'forumplusone'), array('class' => 'accesshide')).$files;
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
<div class="forumplusone-reply-wrapper$t->thresholdblocked">
    <form method="post" role="region" aria-label="$t->legend" class="forumplusone-form $t->class" action="$actionurl" autocomplete="off">
        <fieldset>
            <legend>$t->legend</legend>
            $t->thresholdwarning
            <div class="forumplusone-validation-errors" role="alert"></div>
            <div class="forumplusone-post-figure">
                $t->userpicture
            </div>
            <div class="forumplusone-post-body">
                $subjectField
                <textarea name="message" class="hidden"></textarea>
                <div data-placeholder="$t->messageplaceholder" aria-label="$messagelabel" contenteditable="true" required="required" spellcheck="true" role="textbox" aria-multiline="true" class="forumplusone-textarea">$t->message</div>

                $files

                $t->extrahtml
                $hidden
                <button type="submit">$t->submitlabel</button>
                <a href="#" class="forumplusone-cancel disable-router">$t->cancellabel</a>
                <a href="$advancedurl" aria-pressed="false" class="forumplusone-use-advanced disable-router">$t->advancedlabel</a>
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
            'moodle-mod_forumplusone-article',
            'M.mod_forumplusone.init_article',
            array(array(
                'contextId' => $contextid,
            ))
        );
        $this->page->requires->strings_for_js(array(
            'replytox',
            'xdiscussions',
            'deletesure',
        ), 'mod_forumplusone');
        $this->page->requires->string_for_js('changesmadereallygoaway', 'moodle');
    }

    protected function get_post_user_url($cm, $postuser) {
        if (!$postuser->user_picture->link) {
            return null;
        }
        return new moodle_url('/user/profile.php', array('id' => $postuser->id));
    }

    protected function notification_area() {
        return "<div class='forumplusone-notification'aria-hidden='true' aria-live='assertive'></div>";
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

        $discussionlink = new moodle_url('/mod/forumplusone/discuss.php', array('d' => $post->discussion));
        $ownpost        = (isloggedin() and $post->userid == $USER->id);
        $commands       = array();

        if (!property_exists($post, 'privatereply')) {
            throw new coding_exception('Must set post\'s privatereply property!');
        }

        $forum = forumplusone_get_cm_forum($cm);

        $postuser   = forumplusone_extract_postuser($post, $forum, context_module::instance($cm->id));

        $rating = $this->post_rating($post);
        if (!empty($rating)) {
            $commands['rating'] = $rating;
        }

        if ($canreply and empty($post->privatereply)) {
            $replytitle = get_string('replybuttontitle', 'forumplusone', strip_tags($postuser->fullname));
            $commands['reply'] = html_writer::link(
                new moodle_url('/mod/forumplusone/post.php', array('reply' => $post->id)),
                get_string('reply', 'forumplusone'),
                array(
                    'title' => $replytitle,
                    'class' => 'forumplusone-reply-link',
                )
            );
        }

        // Hack for allow to edit news posts those are not displayed yet until they are displayed
        $age = time() - $post->created;
        if (!$post->parent && $forum->type == 'news' && $discussion->timestart > time()) {
            $age = 0;
        }
        if (($ownpost && $age < $CFG->maxeditingtime) || has_capability('mod/forumplusone:editanypost', context_module::instance($cm->id))) {
            $commands['edit'] = html_writer::link(
                new moodle_url('/mod/forumplusone/post.php', array('edit' => $post->id)),
                get_string('edit', 'forumplusone'),
                array( 'class' => 'forumplusone-edit-link' )
            );
        }

        if (($ownpost && $age < $CFG->maxeditingtime && has_capability('mod/forumplusone:deleteownpost', context_module::instance($cm->id))) || has_capability('mod/forumplusone:deleteanypost', context_module::instance($cm->id))) {
            $commands['delete'] = html_writer::link(
                new moodle_url('/mod/forumplusone/post.php', array('delete' => $post->id)),
                get_string('delete', 'forumplusone'),
                array( 'class' => 'forumplusone-delete-link' )
            );
        }

        if (has_capability('mod/forumplusone:splitdiscussions', context_module::instance($cm->id))
                && $post->parent
                && !$post->privatereply
                && $forum->type != 'single') {
            $commands['split'] = html_writer::link(
                new moodle_url('/mod/forumplusone/post.php', array('prune' => $post->id)),
                get_string('prune', 'forumplusone'),
                array(
                    'title' => get_string('pruneheading', 'forumplusone'),
                    'class' => 'forumplusone-prune-link'
                )
            );
        }


        if ($CFG->enableportfolios && empty($forum->anonymous) && (has_capability('mod/forumplusone:exportpost', context_module::instance($cm->id)) || ($ownpost && has_capability('mod/forumplusone:exportownpost', context_module::instance($cm->id))))) {
            require_once($CFG->libdir.'/portfoliolib.php');
            $button = new portfolio_add_button();
            $button->set_callback_options('forumplusone_portfolio_caller', array('postid' => $post->id), 'mod_forumplusone');
            list($attachments, $attachedimages) = forumplusone_print_attachments($post, $cm, 'separateimages');
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

        // here, the usage of $canreply is a hack  avoid to add a "$canvote" on prototype of the function
        $canvote = $canreply && $forum->enable_vote;
        $isInTime = (time() >= $forum->votetimestart && time() <= $forum->votetimestop) || ($forum->votetimestart == 0 && $forum->votetimestop == 0);

        if ($canvote and $isInTime and !$ownpost) {
            $votetitle = get_string('votebuttontitle', 'forumplusone', strip_tags($postuser->fullname));
            $classes = 'forumplusone-vote-link';

            if (forumplusone_has_vote($post->id, $USER->id)) {
                $classes .= ' active';
                $votetitle = get_string('hasVotebuttontitle', 'forumplusone', strip_tags($postuser->fullname));
            }

            $commands['vote'] = html_writer::link(
                new moodle_url('/mod/forumplusone/post.php', array(
                    'vote' => $post->id)
                    // TODO CSRF !!!
                ),
                get_string('vote', 'forumplusone'),
                array(
                    'title' => $votetitle,
                    'class' => $classes,
                    'data-toggle' => 'tooltip',
                    'data-placement' => 'top',
                    'data-text-vote' => get_string('votebuttontitle', 'forumplusone', strip_tags($postuser->fullname)),
                    'data-text-has-vote' => get_string('hasVotebuttontitle', 'forumplusone', strip_tags($postuser->fullname))
                )
            );
        }

        $canSeeVoter = $canvote;
        $onSchedule = true;

        if ( $forum->vote_display_name && !has_capability('mod/forumplusone:viewwhovote', context_module::instance($cm->id)))
            $onSchedule = false;

        if ( !$forum->vote_display_name && !has_capability('mod/forumplusone:viewwhovote_annonymousvote', context_module::instance($cm->id)))
            $onSchedule = false;


        $spanClass = 'forumplusone-show-voters-link';
        $spanContent = get_string('countvote', 'forumplusone', html_writer::span($post->votecount, 'forumplusone-votes-counter'));

        if ($canSeeVoter && $onSchedule) {
            $commands['countVote'] = html_writer::link(
                new moodle_url('/mod/forumplusone/whovote.php', array(
                    'postid' => $post->id,
                    'contextid' => context_module::instance($cm->id)->id
                )),
                $spanContent,
                array(
                    'class' => $spanClass,
                    'data-toggle' => 'tooltip',
                    'data-placement' => 'top',
                    'title' => get_string('show-voters-link-title', 'forumplusone'),
                )
            );
        }
        else if ($canSeeVoter) {
            $commands['countVote'] = html_writer::span(
                $spanContent,
                $spanClass
            );
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
                '<div>'.get_string('previousdiscussion', 'forumplusone').'</div>'.
                '<div><a href="'.$prevurl->out().'">'.format_string($prevdiscussion->name).'</a></div>'.
                '</div>';
        }
        if ($nextdiscussion) {
            $nexturl = new moodle_url($PAGE->URL);
            $nexturl->param('d', $nextdiscussion->id);
            $nextlink = '<div class="navigatenext">'.
                '<div>'.get_string('nextdiscussion', 'forumplusone').'</div>'.
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
             viewBox="0 0 100 100" enable-background="new 0 0 100 100" xmlns:xlink="http://www.w3.org/1999/xlink">
            <defs>
                <symbol id="icon-open" viewBox="0 0 32 32">
                    <title>open</title>
                    <path class="path1" d="M24 2c3.308 0 6 2.692 6 6v6h-4v-6c0-1.103-0.897-2-2-2h-4c-1.103 0-2 0.897-2 2v6h0.5c0.825 0 1.5 0.675 1.5 1.5v15c0 0.825-0.675 1.5-1.5 1.5h-17c-0.825 0-1.5-0.675-1.5-1.5v-15c0-0.825 0.675-1.5 1.5-1.5h12.5v-6c0-3.308 2.692-6 6-6h4z"></path>
                </symbol>
                <symbol id="icon-close" viewBox="0 0 32 32">
                    <title>close</title>
                    <path class="path1" d="M18.5 14h-0.5v-6c0-3.308-2.692-6-6-6h-4c-3.308 0-6 2.692-6 6v6h-0.5c-0.825 0-1.5 0.675-1.5 1.5v15c0 0.825 0.675 1.5 1.5 1.5h17c0.825 0 1.5-0.675 1.5-1.5v-15c0-0.825-0.675-1.5-1.5-1.5zM6 8c0-1.103 0.897-2 2-2h4c1.103 0 2 0.897 2 2v6h-8v-6z"></path>
                </symbol>
                <symbol id="icon-hide" viewBox="0 0 32 32">
                    <title>hide</title>
                    <path class="path1" d="M29.561 0.439c-0.586-0.586-1.535-0.586-2.121 0l-6.318 6.318c-1.623-0.492-3.342-0.757-5.122-0.757-6.979 0-13.028 4.064-16 10 1.285 2.566 3.145 4.782 5.407 6.472l-4.968 4.968c-0.586 0.586-0.586 1.535 0 2.121 0.293 0.293 0.677 0.439 1.061 0.439s0.768-0.146 1.061-0.439l27-27c0.586-0.586 0.586-1.536 0-2.121zM13 10c1.32 0 2.44 0.853 2.841 2.037l-3.804 3.804c-1.184-0.401-2.037-1.521-2.037-2.841 0-1.657 1.343-3 3-3zM3.441 16c1.197-1.891 2.79-3.498 4.67-4.697 0.122-0.078 0.246-0.154 0.371-0.228-0.311 0.854-0.482 1.776-0.482 2.737 0 1.715 0.54 3.304 1.459 4.607l-1.904 1.904c-1.639-1.151-3.038-2.621-4.114-4.323z"></path>
                    <path class="path2" d="M24 13.813c0-0.849-0.133-1.667-0.378-2.434l-10.056 10.056c0.768 0.245 1.586 0.378 2.435 0.378 4.418 0 8-3.582 8-8z"></path>
                    <path class="path3" d="M25.938 9.062l-2.168 2.168c0.040 0.025 0.079 0.049 0.118 0.074 1.88 1.199 3.473 2.805 4.67 4.697-1.197 1.891-2.79 3.498-4.67 4.697-2.362 1.507-5.090 2.303-7.889 2.303-1.208 0-2.403-0.149-3.561-0.439l-2.403 2.403c1.866 0.671 3.873 1.036 5.964 1.036 6.978 0 13.027-4.064 16-10-1.407-2.81-3.504-5.2-6.062-6.938z"></path>
                </symbol>
                <symbol id="chevron-up" viewBox="0 0 32 32">
                    <path d="M.15524405 22.3029L14.219321 10.3265c.986435-.8405 2.5747-.8405 3.561135 0l14.0643 11.9773" stroke-linecap="round" stroke-linejoin="round"></path>
                </symbol>
                <symbol id="chevron-down" viewBox="0 0 32 32">
                    <path d="M.15524405 22.3029L14.219321 10.3265c.986435-.8405 2.5747-.8405 3.561135 0l14.0643 11.9773" stroke-linecap="round" stroke-linejoin="round" transform="translate(32,32) scale(-1, -1)"></path>
                </symbol>
            </defs>
            <g id="substantive">
                <polygon points="49.9,3.1 65,33.8 99,38.6 74.4,62.6 80.2,96.3 49.9,80.4 19.7,96.3 25.4,62.6
                0.9,38.6 34.8,33.8 "/>
            </g>
            <g id="bookmark">
                <polygon points="88.7,93.2 50.7,58.6 12.4,93.2 12.4,7.8 88.7,7.8 "/>
            </g>
            <g id="subscribe">
               <polygon enable-background="new" points="96.7,84.3 3.5,84.3 3.5,14.8 50.1,49.6 96.7,14.8"/>
               <polygon points="3.5,9.8 96.7,9.8 50.2,44.5"/>
            </g>
            <g id="collapse">
                <use xlink:href="#chevron-up"/>
                <use xlink:href="#chevron-up" y="25%"/>
            </g>
            <g id="uncollapse">
                <use xlink:href="#chevron-down"/>
                <use xlink:href="#chevron-down" y="25%"/>
            </g>
        </svg>';
    }
}
