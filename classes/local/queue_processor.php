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
 * Background queue processor for assignfeedback_aifeedback guidance requests.
 *
 * @package   assignfeedback_aifeedback
 * @copyright 2026 Daniel McCluskey
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aifeedback\local;

use assignfeedback_aifeedback\task\process_queue_request;
use core\task\manager as task_manager;

/**
 * Processes queued requests and stores results.
 */
class queue_processor {
    /** @var string Queue status while a request is running. */
    public const STATUS_PROCESSING = 'processing';
    /** @var string Queue status when processing finished successfully. */
    public const STATUS_COMPLETED = 'completed';
    /** @var string Queue status when processing failed. */
    public const STATUS_FAILED = 'failed';
    /** @var int Retry delay while waiting for file conversion completion. */
    private const CONVERSION_WAIT_RETRY_SECONDS = 30;
    /** @var int Maximum attempts before conversion wait is treated as failed. */
    private const MAX_CONVERSION_WAIT_ATTEMPTS = 6;

    /**
     * Enqueue background processing for one queue record.
     *
     * @param int $requestid Queue request ID.
     * @param int $delayseconds Seconds to delay before the task runs.
     * @return void
     */
    public static function queue_request_processing(int $requestid, int $delayseconds = 0): void {
        $task = new process_queue_request();
        $task->set_custom_data(['requestid' => $requestid]);

        if ($delayseconds > 0) {
            $task->set_next_run_time(time() + $delayseconds);
            task_manager::reschedule_or_queue_adhoc_task($task);
            return;
        }

        task_manager::queue_adhoc_task($task, true);
    }

    /**
     * Process one queue request, if it is still queued.
     *
     * @param int $requestid Queue request ID.
     * @return void
     */
    public static function process_request(int $requestid): void {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('assignfeedback_aifeedback_queue');
        $lock = $lockfactory->get_lock('request_' . $requestid, 0);
        if (!$lock) {
            return;
        }

        try {
            $request = $DB->get_record('assignfeedback_aifeedback_q', ['id' => $requestid], '*', IGNORE_MISSING);
            if (!$request || (string) $request->status !== request_manager::STATUS_QUEUED) {
                return;
            }

            $now = time();
            $DB->update_record('assignfeedback_aifeedback_q', (object) [
                'id' => $requestid,
                'status' => self::STATUS_PROCESSING,
                'attempts' => ((int) $request->attempts) + 1,
                'errorcode' => null,
                'errormessage' => null,
                'timemodified' => $now,
            ]);

            $result = request_manager::generate_ai_feedback($requestid);
            self::store_generation_result($requestid, $result);
        } catch (\Throwable $exception) {
            self::mark_failed(
                $requestid,
                'processing_exception',
                $exception->getMessage(),
                [
                    'exceptionclass' => $exception::class,
                ]
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Store one generation attempt result.
     *
     * @param int $requestid Queue request ID.
     * @param array $result Generation result.
     * @return void
     */
    private static function store_generation_result(int $requestid, array $result): void {
        global $DB;

        $queuestatus = self::STATUS_COMPLETED;
        $errorcode = null;
        $errormessage = null;
        $prompttext = null;

        $captureprompt = !empty(get_config('assignfeedback_aifeedback', 'captureprompt'));
        if ($captureprompt && !empty($result['prompttext']) && is_string($result['prompttext'])) {
            $prompttext = $result['prompttext'];
        }

        if (($result['status'] ?? '') !== 'completed') {
            $queuestatus = self::STATUS_FAILED;

            if (($result['status'] ?? '') === 'payload_failed') {
                $issue = $result['payloadresult']['issues'][0] ?? [];
                $errorcode = self::truncate_error_code((string) ($issue['code'] ?? 'payload_failed'));
                $errormessage = trim((string) ($issue['message'] ?? 'Payload resolution failed.'));
            } else {
                $aipayload = is_array($result['ai'] ?? null) ? $result['ai'] : [];
                $errorcode = self::truncate_error_code((string) ($aipayload['errorcode'] ?? 'ai_error'));
                $errormessage = trim((string) ($aipayload['errormessage'] ?? 'AI processing failed.'));
            }
        }

        $storedresult = [
            'generationstatus' => (string) ($result['status'] ?? ''),
            'payloadstatus' => (string) ($result['payloadresult']['status'] ?? ''),
            'issues' => array_values((array) ($result['payloadresult']['issues'] ?? [])),
            'ai' => $result['ai'] ?? null,
            'contentstats' => (array) ($result['contentstats'] ?? []),
            'structuredfeedback' => $result['structuredfeedback'] ?? null,
        ];

        if (($result['status'] ?? '') === 'payload_waiting_conversion') {
            self::handle_waiting_for_conversion($requestid, $storedresult);
            return;
        }

        $now = time();
        $DB->update_record('assignfeedback_aifeedback_q', (object) [
            'id' => $requestid,
            'status' => $queuestatus,
            'resultjson' => self::safe_json_encode($storedresult),
            'prompttext' => $prompttext,
            'errorcode' => $errorcode !== null ? $errorcode : null,
            'errormessage' => $errormessage !== null ? $errormessage : null,
            'timecompleted' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Requeue generation while conversion is still pending, with a bounded retry count.
     *
     * @param int $requestid Queue request ID.
     * @param array $storedresult Structured result payload to persist.
     * @return void
     */
    private static function handle_waiting_for_conversion(int $requestid, array $storedresult): void {
        global $DB;

        $request = $DB->get_record('assignfeedback_aifeedback_q', ['id' => $requestid], '*', MUST_EXIST);
        $attempts = (int) $request->attempts;

        if ($attempts >= self::MAX_CONVERSION_WAIT_ATTEMPTS) {
            $storedresult['generationstatus'] = 'payload_waiting_conversion_timeout';
            $errormessage = get_string('payloaderror_conversiontimeout', 'assignfeedback_aifeedback');
            $now = time();
            $DB->update_record('assignfeedback_aifeedback_q', (object) [
                'id' => $requestid,
                'status' => self::STATUS_FAILED,
                'resultjson' => self::safe_json_encode($storedresult),
                'errorcode' => self::truncate_error_code('conversion_pending_timeout'),
                'errormessage' => $errormessage,
                'timecompleted' => $now,
                'timemodified' => $now,
            ]);
            return;
        }

        $now = time();
        $DB->update_record('assignfeedback_aifeedback_q', (object) [
            'id' => $requestid,
            'status' => request_manager::STATUS_QUEUED,
            'resultjson' => self::safe_json_encode($storedresult),
            'errorcode' => null,
            'errormessage' => null,
            'timecompleted' => 0,
            'timemodified' => $now,
        ]);

        self::queue_request_processing($requestid, self::CONVERSION_WAIT_RETRY_SECONDS);
    }

    /**
     * Store a failure when processing throws.
     *
     * @param int $requestid Queue request ID.
     * @param string $errorcode Error code.
     * @param string $errormessage Error message.
     * @param array $details Optional structured error details.
     * @return void
     */
    private static function mark_failed(int $requestid, string $errorcode, string $errormessage, array $details = []): void {
        global $DB;

        if (!$DB->record_exists('assignfeedback_aifeedback_q', ['id' => $requestid])) {
            return;
        }

        $detailsjson = self::safe_json_encode([
            'generationstatus' => 'processing_exception',
            'details' => $details,
        ]);

        $now = time();
        $DB->update_record('assignfeedback_aifeedback_q', (object) [
            'id' => $requestid,
            'status' => self::STATUS_FAILED,
            'resultjson' => $detailsjson,
            'errorcode' => self::truncate_error_code($errorcode),
            'errormessage' => trim($errormessage),
            'timecompleted' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Encode a structure to JSON with fallback.
     *
     * @param array $data Data to encode.
     * @return string
     */
    private static function safe_json_encode(array $data): string {
        $encoded = json_encode($data);
        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * Truncate queue error codes to fit DB column constraints.
     *
     * @param string $errorcode Error code.
     * @return string
     */
    private static function truncate_error_code(string $errorcode): string {
        if (\core_text::strlen($errorcode) <= 255) {
            return $errorcode;
        }
        return \core_text::substr($errorcode, 0, 255);
    }
}
