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
 * Adhoc task to process one AI guidance queue request.
 *
 * @package   assignfeedback_aifeedback
 * @copyright 2026 Daniel McCluskey
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aifeedback\task;

use assignfeedback_aifeedback\local\queue_processor;

/**
 * Background task for request queue processing.
 */
class process_queue_request extends \core\task\adhoc_task {
    /**
     * Return task name for admin task lists.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskprocessqueuerequest', 'assignfeedback_aifeedback');
    }

    /**
     * Execute one queue request.
     *
     * @return void
     */
    public function execute() {
        $data = $this->get_custom_data();
        $requestid = isset($data->requestid) ? (int) $data->requestid : 0;
        if ($requestid <= 0) {
            return;
        }

        queue_processor::process_request($requestid);
    }

    /**
     * Use queue status persistence instead of adhoc task retries.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }
}
