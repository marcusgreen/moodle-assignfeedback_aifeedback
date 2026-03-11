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
 * Request queue manager for assignfeedback_aifeedback.
 *
 * @package   assignfeedback_aifeedback
 * @copyright 2026 Daniel McCluskey
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aifeedback\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates queue records and delegates processing.
 */
class request_manager {
    /** @var string Queue status when a request is waiting to be processed. */
    public const STATUS_QUEUED = 'queued';

    /**
     * Create a new queue record for a single grading request.
     *
     * @param \assign $assignment Assignment instance.
     * @param int $foruserid Student user ID being graded.
     * @param int $requestedby Teacher user ID creating the request.
     * @return \stdClass Inserted queue record.
     */
    public static function create_request(\assign $assignment, int $foruserid, int $requestedby): \stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $timecreated = time();
        $grade = $assignment->get_user_grade($foruserid, false);
        $submission = $assignment->get_user_submission($foruserid, false);

        $record = (object) [
            'assignment' => (int) $assignment->get_instance()->id,
            'coursemoduleid' => (int) $assignment->get_course_module()->id,
            'contextid' => (int) $assignment->get_context()->id,
            'foruserid' => $foruserid,
            'requestedby' => $requestedby,
            'gradeid' => $grade ? (int) $grade->id : null,
            'attemptnumber' => $submission ? (int) $submission->attemptnumber : -1,
            'status' => self::STATUS_QUEUED,
            'attempts' => 0,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
        ];

        $record->id = $DB->insert_record('assignfeedback_aifeedback_q', $record);
        queue_processor::queue_request_processing((int) $record->id);

        $transaction->allow_commit();
        return $record;
    }

    /**
     * Resolve a queued request into an AI-ready payload structure.
     *
     * @param int $requestid Queue request ID.
     * @return array Structured payload resolution result.
     */
    public static function resolve_request_payload(int $requestid): array {
        return request_payload_resolver::resolve_from_request_id($requestid);
    }

    /**
     * Resolve a queued request and send it to the AI subsystem.
     *
     * @param int $requestid Queue request ID.
     * @return array Structured AI generation result.
     */
    public static function generate_ai_feedback(int $requestid): array {
        return request_ai_generator::generate_from_request_id($requestid);
    }
}
