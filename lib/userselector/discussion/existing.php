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
 * Discussion existing user selector
 *
 * @package    mod
 * @subpackage forumplusone
 * @copyright  Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__.'/abstract.php');

class forumplusone_userselector_discussion_existing extends forumplusone_userselector_discussion_abstract {
    /**
     * Get file path to this class
     *
     * @return string
     */
    public function get_filepath() {
        return '/mod/forumplusone/lib/userselector/discussion/existing.php';
    }

    public function find_users($search) {
        return array(
            get_string("existingsubscribers", 'forumplusone') =>
            $this->get_repo()->get_subscribed_users($this->forum, $this->discussion, $this->context, $this->currentgroup, $this->required_fields_sql('u'), $this->search_sql($search, 'u'))
        );
    }
}
