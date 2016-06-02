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
 * CSV Export Format
 *
 * @package   mod_forumplusone
 * @copyright Copyright (c) 2013 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_forumplusone\export;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__.'/format_abstract.php');

/**
 * @package   mod_forumplusone
 * @copyright Copyright (c) 2013 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class csv_format extends format_abstract {

    public function init($file) {
        parent::init($file);

        // Write out CSV headers.
        fputcsv($this->fp, array(
            get_string('id', 'forumplusone'),
            get_string('discussion', 'forumplusone'),
            get_string('subject', 'forumplusone'),
            get_string('author', 'forumplusone'),
            get_string('date', 'forumplusone'),
            get_string('message', 'forumplusone'),
            get_string('attachments', 'forumplusone'),
            get_string('privatereply', 'forumplusone'),
        ));
    }

    /**
     * Get the file extension generated by the export class
     *
     * @return string
     */
    public function get_extension() {
        return 'csv';
    }

    /**
     * @param int $id Post ID
     * @param string $subject Discussion subject
     * @param string $author Author name ready for printing
     * @param int $date The timestamp
     * @param string $message The message
     * @param array $attachments Attachment file names
     * @return mixed
     */
    public function export_discussion($id, $subject, $author, $date, $message, $attachments) {
        $this->export_post($id, $subject, $subject, $author, $date, $message, $attachments, '');
    }

    /**
     * @param int $id Post ID
     * @param string $discussion Discussion subject
     * @param string $subject Post subject
     * @param string $author Author name ready for printing
     * @param int $date The timestamp
     * @param string $message The message
     * @param array $attachments Attachment file names
     * @param string $private Yes if private reply
     * @return mixed
     */
    public function export_post($id, $discussion, $subject, $author, $date, $message, $attachments, $private) {
        $userdate = userdate($date, get_string('strftimedatefullshort').' '.get_string('strftimetime'));
        fputcsv($this->fp, array($id, $discussion, $subject, $author, $userdate, $message, implode(' | ', $attachments), $private));
    }
}
