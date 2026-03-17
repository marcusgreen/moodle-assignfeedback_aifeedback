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
 * Privacy provider for assignfeedback_aifeedback.
 *
 * @package   assignfeedback_aifeedback
 * @copyright 2026 Daniel McCluskey
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aifeedback\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy provider for assignfeedback_aifeedback.
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Return metadata describing stored personal data.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('assignfeedback_aifeedback_q', [
            'assignment' => 'privacy:metadata:assignment',
            'coursemoduleid' => 'privacy:metadata:coursemoduleid',
            'contextid' => 'privacy:metadata:contextid',
            'foruserid' => 'privacy:metadata:foruserid',
            'requestedby' => 'privacy:metadata:requestedby',
            'gradeid' => 'privacy:metadata:gradeid',
            'attemptnumber' => 'privacy:metadata:attemptnumber',
            'status' => 'privacy:metadata:status',
            'attempts' => 'privacy:metadata:attempts',
            'resultjson' => 'privacy:metadata:resultjson',
            'prompttext' => 'privacy:metadata:prompttext',
            'errorcode' => 'privacy:metadata:errorcode',
            'errormessage' => 'privacy:metadata:errormessage',
            'timecompleted' => 'privacy:metadata:timecompleted',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:requestqueue');

        return $collection;
    }
}
