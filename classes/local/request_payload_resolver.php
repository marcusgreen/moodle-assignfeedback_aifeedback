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
 * Queue request payload resolver for assignfeedback_aifeedback.
 *
 * @package   assignfeedback_aifeedback
 * @copyright 2026 Daniel McCluskey
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aifeedback\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

use core_files\conversion;
use core_files\converter;

/**
 * Resolves queued requests into AI-ready payload data.
 */
class request_payload_resolver {
    /** @var string Ready status with no extraction issues. */
    public const STATUS_READY = 'ready';
    /** @var string Ready status with warnings/issues. */
    public const STATUS_READY_WITH_WARNINGS = 'ready_with_warnings';
    /** @var string Failed status when no extractable content could be resolved. */
    public const STATUS_FAILED = 'failed';

    /** @var string[] Supported file extensions for extraction in this stage. */
    private const SUPPORTED_EXTENSIONS = ['pdf', 'docx'];
    /** @var string Regex matching DOCX XML parts that contain user-written text. */
    private const DOCX_TEXT_PART_PATTERN = '/^word\/(?:document|header\d+|footer\d+)\.xml$/i';

    /**
     * Resolve a queue request into an AI-ready payload.
     *
     * @param int $requestid Queue request ID.
     * @return array Structured payload result.
     */
    public static function resolve_from_request_id(int $requestid): array {
        global $DB;

        $request = $DB->get_record('assignfeedback_aifeedback_q', ['id' => $requestid], '*', MUST_EXIST);
        return self::resolve_from_request($request);
    }

    /**
     * Resolve a queue record into an AI-ready payload.
     *
     * @param \stdClass $request Queue record.
     * @return array Structured payload result.
     */
    public static function resolve_from_request(\stdClass $request): array {
        $issues = [];
        $assignment = self::load_assignment_for_request($request);
        $instance = $assignment->get_instance();

        $payload = [
            'assignment' => [
                'id' => (int) $instance->id,
                'name' => format_string($instance->name, true, ['context' => $assignment->get_context()]),
                'description' => trim(content_to_text((string) $instance->intro, (int) $instance->introformat)),
                'gradingscheme' => grading_scheme_resolver::resolve_from_assignment($assignment),
            ],
            'submission' => [
                'userid' => (int) $request->foruserid,
                'attemptnumber' => (int) $request->attemptnumber,
                'onlinetext' => [
                    'status' => 'not_present',
                    'text' => '',
                ],
                'files' => [],
                'combinedtext' => '',
            ],
        ];

        if ((int) $request->assignment !== (int) $instance->id) {
            $issues[] = self::make_issue(
                'assignment_mismatch',
                get_string('payloaderror_assignmentmismatch', 'assignfeedback_aifeedback')
            );
            return self::build_result($request, self::STATUS_FAILED, $payload, $issues);
        }

        $submission = $assignment->get_user_submission(
            (int) $request->foruserid,
            false,
            (int) $request->attemptnumber
        );

        if (!$submission) {
            $issues[] = self::make_issue(
                'submission_missing',
                get_string('payloaderror_submissionmissing', 'assignfeedback_aifeedback')
            );
            return self::build_result($request, self::STATUS_FAILED, $payload, $issues);
        }

        $payload['submission']['onlinetext'] = self::collect_online_text($assignment, $submission);
        $payload['submission']['files'] = self::collect_supported_files(
            $assignment,
            $submission,
            (int) $request->foruserid,
            $issues
        );
        $payload['submission']['combinedtext'] = self::build_combined_submission_text($payload['submission']);

        if ($payload['submission']['combinedtext'] === '') {
            $issues[] = self::make_issue(
                'no_extractable_content',
                get_string('payloaderror_nocontent', 'assignfeedback_aifeedback')
            );
            return self::build_result($request, self::STATUS_FAILED, $payload, $issues);
        }

        $status = empty($issues) ? self::STATUS_READY : self::STATUS_READY_WITH_WARNINGS;
        return self::build_result($request, $status, $payload, $issues);
    }

    /**
     * Create a standard structured result object.
     *
     * @param \stdClass $request Queue request.
     * @param string $status Overall resolver status.
     * @param array $payload AI-ready payload.
     * @param array $issues Collected issues.
     * @return array
     */
    private static function build_result(\stdClass $request, string $status, array $payload, array $issues): array {
        return [
            'requestid' => (int) $request->id,
            'status' => $status,
            'payload' => $payload,
            'issues' => array_values($issues),
        ];
    }

    /**
     * Load assignment object from queue request context data.
     *
     * @param \stdClass $request Queue request.
     * @return \assign
     */
    private static function load_assignment_for_request(\stdClass $request): \assign {
        $cm = get_coursemodule_from_id('assign', (int) $request->coursemoduleid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $course = get_course($cm->course);

        return new \assign($context, $cm, $course);
    }

    /**
     * Collect online text submission content, if present.
     *
     * @param \assign $assignment Assignment object.
     * @param \stdClass $submission Submission record.
     * @return array
     */
    private static function collect_online_text(\assign $assignment, \stdClass $submission): array {
        $plugin = $assignment->get_submission_plugin_by_type('onlinetext');
        if (!$plugin || !$plugin->is_enabled()) {
            return [
                'status' => 'not_configured',
                'text' => '',
            ];
        }

        if ($plugin->is_empty($submission)) {
            return [
                'status' => 'not_present',
                'text' => '',
            ];
        }

        $rawtext = (string) $plugin->get_editor_text('onlinetext', $submission->id);
        $format = (int) $plugin->get_editor_format('onlinetext', $submission->id);
        $plaintext = trim(content_to_text($rawtext, $format));

        if ($plaintext === '') {
            return [
                'status' => 'empty',
                'text' => '',
            ];
        }

        return [
            'status' => 'ok',
            'text' => $plaintext,
        ];
    }

    /**
     * Collect and extract supported file submission content.
     *
     * @param \assign $assignment Assignment object.
     * @param \stdClass $submission Submission record.
     * @param int $userid Student user ID.
     * @param array $issues Collected issues list (by reference).
     * @return array
     */
    private static function collect_supported_files(
        \assign $assignment,
        \stdClass $submission,
        int $userid,
        array &$issues
    ): array {
        $plugin = $assignment->get_submission_plugin_by_type('file');
        if (!$plugin || !$plugin->is_enabled() || $plugin->is_empty($submission)) {
            return [];
        }

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $files = $plugin->get_files($submission, $user);
        $results = [];

        foreach ($files as $file) {
            if (!$file instanceof \stored_file || $file->is_directory()) {
                continue;
            }

            $fileresult = self::extract_file_content($file);
            $results[] = $fileresult;

            if ($fileresult['status'] !== 'ok') {
                $issues[] = self::make_issue(
                    (string) $fileresult['errorcode'],
                    (string) $fileresult['errormessage'],
                    [
                        'fileid' => (int) $file->get_id(),
                        'filename' => $file->get_filename(),
                    ]
                );
            }
        }

        return $results;
    }

    /**
     * Extract supported text content from a submitted file.
     *
     * @param \stored_file $file Submitted file.
     * @return array
     */
    private static function extract_file_content(\stored_file $file): array {
        $extension = \core_text::strtolower((string) pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $result = [
            'fileid' => (int) $file->get_id(),
            'filename' => $file->get_filename(),
            'mimetype' => $file->get_mimetype(),
            'sizebytes' => (int) $file->get_filesize(),
            'extension' => $extension,
            'status' => 'unsupported_type',
            'text' => '',
            'extractionmethod' => '',
            'errorcode' => '',
            'errormessage' => '',
        ];

        if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            $result['errorcode'] = 'unsupported_file_type';
            $result['errormessage'] = get_string('payloaderror_unsupportedfiletype', 'assignfeedback_aifeedback');
            return $result;
        }

        // DOCX can be parsed directly, which avoids mandatory external converter setup.
        if ($extension === 'docx') {
            $directtext = self::extract_docx_text_direct($file);
            if ($directtext !== '') {
                $result['status'] = 'ok';
                $result['text'] = $directtext;
                $result['extractionmethod'] = 'docx_internal';
                return $result;
            }
        }

        return self::extract_via_converter($file, $result);
    }

    /**
     * Extract text content by converting the file to plain text via Moodle file converters.
     *
     * @param \stored_file $file Submitted file.
     * @param array $result Base file extraction result payload.
     * @return array
     */
    private static function extract_via_converter(\stored_file $file, array $result): array {
        $converter = new converter();
        if (!$converter->can_convert_storedfile_to($file, 'txt')) {
            $result['status'] = 'unsupported_conversion';
            $result['errorcode'] = 'conversion_not_supported';
            $result['errormessage'] = get_string('payloaderror_conversionunsupported', 'assignfeedback_aifeedback');
            return $result;
        }

        $conversion = $converter->start_conversion($file, 'txt');
        $status = (int) $conversion->get('status');

        if ($status === conversion::STATUS_PENDING || $status === conversion::STATUS_IN_PROGRESS) {
            $result['status'] = 'conversion_pending';
            $result['errorcode'] = 'conversion_pending';
            $result['errormessage'] = get_string('payloaderror_conversionpending', 'assignfeedback_aifeedback');
            return $result;
        }

        if ($status !== conversion::STATUS_COMPLETE) {
            $result['status'] = 'conversion_failed';
            $result['errorcode'] = 'conversion_failed';
            $result['errormessage'] = get_string(
                'payloaderror_conversionfailed',
                'assignfeedback_aifeedback',
                (string) $conversion->get('statusmessage')
            );
            return $result;
        }

        $destinationfile = $conversion->get_destfile();
        if (!$destinationfile) {
            $result['status'] = 'conversion_failed';
            $result['errorcode'] = 'conversion_output_missing';
            $result['errormessage'] = get_string('payloaderror_conversionoutputmissing', 'assignfeedback_aifeedback');
            return $result;
        }

        $text = trim((string) $destinationfile->get_content());
        if ($text === '') {
            $result['status'] = 'empty';
            $result['errorcode'] = 'converted_text_empty';
            $result['errormessage'] = get_string('payloaderror_convertedempty', 'assignfeedback_aifeedback');
            return $result;
        }

        $result['status'] = 'ok';
        $result['text'] = $text;
        $result['extractionmethod'] = 'converter_txt';
        return $result;
    }

    /**
     * Extract text directly from DOCX XML parts.
     *
     * @param \stored_file $file Submitted DOCX file.
     * @return string
     */
    private static function extract_docx_text_direct(\stored_file $file): string {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }

        $temppath = $file->copy_content_to_temp('assignfeedback_aifeedback');
        if ($temppath === false || $temppath === '') {
            return '';
        }

        $zip = new \ZipArchive();
        $parts = [];
        $isopen = false;

        try {
            if ($zip->open($temppath) !== true) {
                return '';
            }
            $isopen = true;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = is_array($stat) && isset($stat['name']) ? (string) $stat['name'] : '';
                if ($name === '' || !preg_match(self::DOCX_TEXT_PART_PATTERN, $name)) {
                    continue;
                }

                $xml = $zip->getFromIndex($index);
                if (!is_string($xml) || $xml === '') {
                    continue;
                }

                $parttext = self::extract_docx_xml_text($xml);
                if ($parttext !== '') {
                    $parts[] = $parttext;
                }
            }
        } finally {
            if ($isopen) {
                $zip->close();
            }
            @unlink($temppath);
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Convert DOCX XML fragments into plain text.
     *
     * @param string $xml One DOCX XML part.
     * @return string
     */
    private static function extract_docx_xml_text(string $xml): string {
        $text = preg_replace('/<w:(tab|ptab)\b[^>]*\/>/i', "\t", $xml);
        $text = preg_replace('/<w:(br|cr)\b[^>]*\/>/i', "\n", (string) $text);
        $text = preg_replace('/<\/w:(p|tr)>/i', "\n", (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace("/[ \t]+\n/u", "\n", $text);
        $text = preg_replace("/\n{3,}/u", "\n\n", (string) $text);
        $text = preg_replace("/\t{2,}/u", "\t", (string) $text);

        return trim((string) $text);
    }

    /**
     * Determine if payload resolution is waiting for asynchronous conversion completion.
     *
     * @param array $resolved Resolved payload result.
     * @return bool
     */
    public static function has_pending_conversion(array $resolved): bool {
        if (($resolved['status'] ?? '') !== self::STATUS_FAILED) {
            return false;
        }

        $submission = $resolved['payload']['submission'] ?? [];
        $files = is_array($submission['files'] ?? null) ? $submission['files'] : [];
        foreach ($files as $file) {
            if (($file['status'] ?? '') === 'conversion_pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * Build one combined plain text blob for AI prompt input.
     *
     * @param array $submission Submission payload segment.
     * @return string
     */
    private static function build_combined_submission_text(array $submission): string {
        $parts = [];

        if (!empty($submission['onlinetext']['status']) && $submission['onlinetext']['status'] === 'ok') {
            $parts[] = $submission['onlinetext']['text'];
        }

        foreach ($submission['files'] as $file) {
            if (!empty($file['status']) && $file['status'] === 'ok' && !empty($file['text'])) {
                $parts[] = $file['text'];
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * Build a structured issue record.
     *
     * @param string $code Machine-readable issue code.
     * @param string $message Human-readable issue message.
     * @param array $details Optional structured issue details.
     * @return array
     */
    private static function make_issue(string $code, string $message, array $details = []): array {
        return array_merge(
            [
                'code' => $code,
                'message' => $message,
            ],
            $details
        );
    }
}
