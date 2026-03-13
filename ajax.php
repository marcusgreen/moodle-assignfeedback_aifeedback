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
 * AJAX endpoint for teacher-facing AI guidance request actions.
 *
 * @package   assignfeedback_aifeedback
 * @copyright 2026 Daniel McCluskey
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignfeedback_aifeedback\local\request_manager;

define('AJAX_SCRIPT', true);

require('../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Build teacher-review text from stored queue result payload.
 *
 * @param array $result Stored queue result payload.
 * @return string
 */
function assignfeedback_aifeedback_build_review_text(array $result): string {
    $sections = [];
    $structured = $result['structuredfeedback'] ?? null;
    if (is_array($structured)) {
        $normaliselist = function ($items): array {
            if (!is_array($items)) {
                return [];
            }
            $normalised = [];
            foreach ($items as $item) {
                $itemtext = trim((string) $item);
                if ($itemtext !== '') {
                    $normalised[] = $itemtext;
                }
            }
            return $normalised;
        };

        $suggestedgrade = trim((string) ($structured['suggested_grade'] ?? ''));
        if ($suggestedgrade !== '') {
            $sections[] = "Suggested grade:\n" . $suggestedgrade;
        }

        $strengths = $normaliselist($structured['strengths'] ?? []);
        if (!empty($strengths)) {
            $sections[] = "Strengths:\n- " . implode("\n- ", $strengths);
        }

        $weaknesses = $normaliselist($structured['weaknesses'] ?? []);
        if (!empty($weaknesses)) {
            $sections[] = "Areas to improve:\n- " . implode("\n- ", $weaknesses);
        }

        $future = $normaliselist($structured['future_improvement_suggestions'] ?? []);
        if (!empty($future)) {
            $sections[] = "Future improvement suggestions:\n- " . implode("\n- ", $future);
        }

        $positive = trim((string) ($structured['positive_tone_summary'] ?? ''));
        if ($positive !== '') {
            $sections[] = "Overall quality summary:\n" . $positive;
        }

        $teacherwording = trim((string) ($structured['teacher_review_wording'] ?? ''));
        if ($teacherwording !== '') {
            $sections[] = "Marker guidance note (rewrite before sharing):\n" . $teacherwording;
        }
    }

    if (!empty($sections)) {
        return trim(implode("\n\n", $sections));
    }

    $aipayload = $result['ai'] ?? null;
    if (is_array($aipayload)) {
        $raw = trim((string) ($aipayload['generatedcontent'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }
    }

    return '';
}

/**
 * Return the latest or specific queue request record for this grading context.
 *
 * @param assign $assignment Assignment instance.
 * @param int $userid Target student user ID.
 * @param int $requestid Specific request ID, or 0 for latest.
 * @return stdClass|null
 */
function assignfeedback_aifeedback_get_request(assign $assignment, int $userid, int $requestid): ?stdClass {
    global $DB;

    $cmid = (int) $assignment->get_course_module()->id;
    if ($requestid > 0) {
        $request = $DB->get_record('assignfeedback_aifeedback_q', ['id' => $requestid], '*', IGNORE_MISSING);
        if (!$request) {
            return null;
        }
        if ((int) $request->coursemoduleid !== $cmid) {
            return null;
        }
        return $request;
    }

    $records = $DB->get_records(
        'assignfeedback_aifeedback_q',
        [
            'coursemoduleid' => $cmid,
            'foruserid' => $userid,
        ],
        'id DESC',
        '*',
        0,
        1
    );

    $request = reset($records);
    return $request ? $request : null;
}

/**
 * Build the status message string for a queue record.
 *
 * @param stdClass $request Queue request record.
 * @param array $result Stored queue result payload.
 * @return string
 */
function assignfeedback_aifeedback_get_status_message(stdClass $request, array $result = []): string {
    if ((string) $request->status === 'queued') {
        if (($result['generationstatus'] ?? '') === 'payload_waiting_conversion') {
            return get_string('requeststatusqueuedconversion', 'assignfeedback_aifeedback');
        }
        return get_string('requeststatusqueued', 'assignfeedback_aifeedback');
    }
    if ((string) $request->status === 'processing') {
        return get_string('requeststatusprocessing', 'assignfeedback_aifeedback');
    }
    if ((string) $request->status === 'completed') {
        $summary = assignfeedback_aifeedback_build_content_summary($result);
        if ($summary !== '') {
            return get_string('requeststatuscompletedwithcontent', 'assignfeedback_aifeedback', $summary);
        }
        return get_string('requeststatuscompleted', 'assignfeedback_aifeedback');
    }
    if ((string) $request->status === 'failed') {
        $error = trim((string) ($request->errormessage ?? ''));
        if ($error !== '') {
            return get_string('requeststatusfailed', 'assignfeedback_aifeedback', $error);
        }
        return get_string('requeststatusfailedgeneric', 'assignfeedback_aifeedback');
    }
    return get_string('requeststatusunknown', 'assignfeedback_aifeedback', (string) $request->status);
}

/**
 * Build a concise summary of what submission content was used by generation.
 *
 * @param array $result Stored queue result payload.
 * @return string
 */
function assignfeedback_aifeedback_build_content_summary(array $result): string {
    $stats = is_array($result['contentstats'] ?? null) ? $result['contentstats'] : [];
    if (empty($stats)) {
        return '';
    }

    $messages = [];
    if (($stats['onlinetextstatus'] ?? '') === 'ok') {
        $messages[] = get_string('contentsummaryonlinetextincluded', 'assignfeedback_aifeedback');
    }

    $filesok = (int) ($stats['files_ok'] ?? 0);
    if ($filesok > 0) {
        $messages[] = get_string('contentsummaryfilesincluded', 'assignfeedback_aifeedback', $filesok);
    }

    $filesskipped = (int) ($stats['files_skipped'] ?? 0);
    if ($filesskipped > 0) {
        $messages[] = get_string('contentsummaryfilesskipped', 'assignfeedback_aifeedback', $filesskipped);
    }

    return trim(implode(' ', $messages));
}

$response = [
    'ok' => false,
    'message' => get_string('requestfailed', 'assignfeedback_aifeedback'),
];

@header('Content-Type: application/json; charset=utf-8');

try {
    global $USER;

    require_sesskey();

    $action = optional_param('action', 'create', PARAM_ALPHAEXT);
    $cmid = required_param('cmid', PARAM_INT);
    $userid = required_param('userid', PARAM_INT);

    $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    $context = context_module::instance($cm->id);

    require_login($course, false, $cm);
    require_capability('mod/assign:grade', $context);
    require_capability('assignfeedback/aifeedback:generate', $context);

    $assignment = new assign($context, $cm, $course);

    $plugin = $assignment->get_feedback_plugin_by_type('aifeedback');
    if (!$plugin || !$plugin->is_enabled()) {
        throw new moodle_exception('pluginnotenabled', 'assignfeedback_aifeedback');
    }

    if (!$assignment->can_view_submission($userid)) {
        throw new moodle_exception('invalidgradingtarget', 'assignfeedback_aifeedback');
    }

    if ($action === 'create') {
        $request = request_manager::create_request($assignment, $userid, (int) $USER->id);

        $response = [
            'ok' => true,
            'requestid' => (int) $request->id,
            'status' => (string) $request->status,
            'timecreated' => (int) $request->timecreated,
            'timemodified' => (int) $request->timemodified,
            'message' => get_string('requestcreated', 'assignfeedback_aifeedback', $request->id),
        ];
    } else if ($action === 'status') {
        $requestid = optional_param('requestid', 0, PARAM_INT);
        $request = assignfeedback_aifeedback_get_request($assignment, $userid, $requestid);

        if (!$request) {
            $response = [
                'ok' => true,
                'requestid' => 0,
                'status' => 'none',
                'hasreviewtext' => false,
                'reviewtext' => '',
                'message' => get_string('statusplaceholder', 'assignfeedback_aifeedback'),
            ];
        } else {
            if (!$assignment->can_view_submission((int) $request->foruserid)) {
                throw new moodle_exception('invalidgradingtarget', 'assignfeedback_aifeedback');
            }

            $result = [];
            if (!empty($request->resultjson)) {
                $decoded = json_decode((string) $request->resultjson, true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }
            $reviewtext = assignfeedback_aifeedback_build_review_text($result);

            $response = [
                'ok' => true,
                'requestid' => (int) $request->id,
                'status' => (string) $request->status,
                'attempts' => (int) $request->attempts,
                'errorcode' => (string) ($request->errorcode ?? ''),
                'errormessage' => (string) ($request->errormessage ?? ''),
                'timecreated' => (int) $request->timecreated,
                'timecompleted' => (int) ($request->timecompleted ?? 0),
                'timemodified' => (int) $request->timemodified,
                'hasreviewtext' => $reviewtext !== '',
                'reviewtext' => $reviewtext,
                'message' => assignfeedback_aifeedback_get_status_message($request, $result),
            ];
        }
    } else {
        throw new moodle_exception('invalidaction', 'assignfeedback_aifeedback');
    }
} catch (Throwable $exception) {
    http_response_code(400);

    if ($exception instanceof moodle_exception) {
        $response['message'] = $exception->getMessage();
    }
}

echo json_encode($response);
die();
