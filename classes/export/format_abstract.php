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
 * Export Format Abstract
 *
 * @package   mod_forumplusone
 * @copyright Copyright (c) 2013 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_forumplusone\export;

defined('MOODLE_INTERNAL') || die();

/**
 * @package   mod_forumplusone
 * @copyright Copyright (c) 2013 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class format_abstract {
    /**
     * The file point to the export file
     *
     * @var resource
     */
    protected $fp;

    /**
     * Init routine
     *
     * @param string $file Absolute path to the file to export to
     * @throws \coding_exception
     * @return void
     */
    public function init($file) {
        $this->fp = fopen($file, 'w');

        if ($this->fp === false) {
            throw new \coding_exception('Failed to open file for writing');
        }
    }

    /**
     * Get the file extension generated by the export class
     *
     * @return string
     */
    abstract public function get_extension();

    /**
     * @param int $id Post ID
     * @param string $subject Discussion subject
     * @param string $author Author name ready for printing
     * @param int $date The timestamp
     * @param string $message The message
     * @param array $attachments Attachment file names
     * @return mixed
     */
    abstract public function export_discussion($id, $subject, $author, $date, $message, $attachments);

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
    abstract public function export_post($id, $discussion, $subject, $author, $date, $message, $attachments, $private);

    /**
     * Close the export
     */
    public function close() {
        fclose($this->fp);
    }
}
