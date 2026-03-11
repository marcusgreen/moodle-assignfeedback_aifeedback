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
 * Teacher UI for AI guidance request and review actions.
 *
 * @module     assignfeedback_aifeedback/requestui
 * @copyright  2026 Daniel McCluskey
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    var STATUS_CLASSES = {
        danger: 'alert alert-danger mt-2',
        info: 'alert alert-info mt-2',
        success: 'alert alert-success mt-2',
    };

    /**
     * Set status panel appearance and content.
     *
     * @param {HTMLElement} node Status container.
     * @param {String} cssclass CSS class list.
     * @param {String} text Message to display.
     */
    var setStatus = function(node, cssclass, text) {
        node.className = cssclass;
        node.textContent = text;
    };

    /**
     * Set refresh activity message.
     *
     * @param {HTMLElement} node Activity container.
     * @param {String} text Message text.
     */
    var setRefreshActivity = function(node, text) {
        node.textContent = text;
    };

    /**
     * Map queue status to status panel css classes.
     *
     * @param {String} status Queue status value.
     * @return {String}
     */
    var getStatusClassForQueueStatus = function(status) {
        if (status === 'completed') {
            return STATUS_CLASSES.success;
        }
        if (status === 'failed') {
            return STATUS_CLASSES.danger;
        }
        return STATUS_CLASSES.info;
    };

    /**
     * Send an ajax request for create/status actions.
     *
     * @param {String} url Endpoint URL.
     * @param {String} body URL encoded payload.
     * @param {Function} ondone Success callback.
     * @param {Function} onerror Error callback.
     */
    var post = function(url, body, ondone, onerror) {
        var request = new XMLHttpRequest();
        request.open('POST', url, true);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

        request.onreadystatechange = function() {
            if (request.readyState !== 4) {
                return;
            }

            var response = null;
            try {
                response = JSON.parse(request.responseText);
            } catch (e) {
                onerror(new Error('invalidresponse'));
                return;
            }

            if (request.status >= 200 && request.status < 300 && response && response.ok) {
                ondone(response);
                return;
            }

            if (response && response.message) {
                onerror(new Error(response.message));
                return;
            }

            onerror(new Error('requestfailed'));
        };

        request.onerror = function() {
            onerror(new Error('networkerror'));
        };

        request.send(body);
    };

    /**
     * Build request payload body.
     *
     * @param {Object} config Initialisation config.
     * @param {String} action Action name.
     * @param {Number} requestid Request ID (or 0).
     * @return {String}
     */
    var buildPayload = function(config, action, requestid) {
        return [
            'sesskey=' + encodeURIComponent(M.cfg.sesskey),
            'action=' + encodeURIComponent(action),
            'cmid=' + encodeURIComponent(config.cmid),
            'userid=' + encodeURIComponent(config.userid),
            'requestid=' + encodeURIComponent(requestid || 0),
        ].join('&');
    };

    /**
     * Apply second count to refresh template.
     *
     * @param {String} template Localised template containing {$a}.
     * @param {Number} value Seconds since last refresh.
     * @return {String}
     */
    var renderSecondsTemplate = function(template, value) {
        return template.replace('{$a}', String(value));
    };

    /**
     * Initialise a single request/review UI instance.
     *
     * @param {Object} config Initialisation config.
     */
    var init = function(config) {
        var generateButton = document.getElementById(config.buttonid);
        var refreshButton = document.getElementById(config.refreshid);
        var status = document.getElementById(config.statusid);
        var refreshInfo = document.getElementById(config.refreshinfoid);
        var output = document.getElementById(config.outputid);
        var currentRequestId = 0;
        var pollTimer = null;
        var refreshTicker = null;
        var pollIntervalMs = parseInt(config.pollintervalms, 10);
        var statusRequestInFlight = false;
        var lastRefreshTimestamp = 0;
        var waitingForResult = false;

        if (!generateButton || !refreshButton || !status || !refreshInfo || !output) {
            return;
        }
        if (isNaN(pollIntervalMs) || pollIntervalMs < 3000) {
            pollIntervalMs = 7000;
        }

        /**
         * Stop polling for status updates.
         */
        var stopPolling = function() {
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        };

        /**
         * Start polling while queue status is not final.
         */
        var startPolling = function() {
            if (pollTimer) {
                return;
            }
            pollTimer = window.setInterval(function() {
                if (document.hidden) {
                    return;
                }
                fetchStatus(false);
            }, pollIntervalMs);
        };

        /**
         * Update "last refreshed" activity text.
         */
        var updateRefreshAgeText = function() {
            var elapsed;

            if (!lastRefreshTimestamp) {
                setRefreshActivity(refreshInfo, config.refreshactivityidle);
                return;
            }

            elapsed = Math.max(0, Math.floor((Date.now() - lastRefreshTimestamp) / 1000));
            if (elapsed === 0) {
                setRefreshActivity(refreshInfo, config.refreshjustnow);
                return;
            }

            setRefreshActivity(refreshInfo, renderSecondsTemplate(config.refreshsecondsago, elapsed));
        };

        /**
         * Start timer to show how recent the last poll was.
         */
        var startRefreshTicker = function() {
            if (refreshTicker) {
                return;
            }

            refreshTicker = window.setInterval(function() {
                if (!waitingForResult) {
                    return;
                }
                updateRefreshAgeText();
            }, 1000);
        };

        /**
         * Stop refresh age timer.
         */
        var stopRefreshTicker = function() {
            if (refreshTicker) {
                window.clearInterval(refreshTicker);
                refreshTicker = null;
            }
        };

        /**
         * Apply status response to UI.
         *
         * @param {Object} response Status response.
         */
        var applyStatusResponse = function(response) {
            var statusclass = getStatusClassForQueueStatus(response.status);

            if (response.requestid) {
                currentRequestId = response.requestid;
            }

            setStatus(status, statusclass, response.message || config.statusplaceholder);

            if (response.hasreviewtext && response.reviewtext) {
                output.value = response.reviewtext;
            } else if (response.status === 'none') {
                output.value = '';
            }

            lastRefreshTimestamp = Date.now();
            updateRefreshAgeText();

            if (response.status === 'queued' || response.status === 'processing') {
                waitingForResult = true;
                startPolling();
                startRefreshTicker();
            } else {
                waitingForResult = false;
                stopPolling();
                stopRefreshTicker();
            }
        };

        /**
         * Request latest queue status for this user.
         *
         * @param {Boolean} manual Whether this was a user-triggered refresh.
         */
        var fetchStatus = function(manual) {
            var payload;

            if (statusRequestInFlight) {
                return;
            }

            setRefreshActivity(refreshInfo, config.refreshactivitychecking);
            payload = buildPayload(config, 'status', currentRequestId);
            statusRequestInFlight = true;
            post(config.ajaxurl, payload, function(response) {
                statusRequestInFlight = false;
                applyStatusResponse(response);
            }, function(error) {
                var message = config.requestfailed;

                statusRequestInFlight = false;
                if (!manual) {
                    updateRefreshAgeText();
                    return;
                }

                if (error && error.message) {
                    message += ' ' + error.message;
                }
                setStatus(status, STATUS_CLASSES.danger, message);
                updateRefreshAgeText();
            });
        };

        generateButton.addEventListener('click', function(event) {
            var payload;

            event.preventDefault();
            generateButton.disabled = true;
            waitingForResult = true;
            output.value = '';
            lastRefreshTimestamp = 0;
            setStatus(status, STATUS_CLASSES.info, config.requesting);
            setRefreshActivity(refreshInfo, config.refreshactivitychecking);
            startRefreshTicker();

            payload = buildPayload(config, 'create', 0);

            post(config.ajaxurl, payload, function(response) {
                if (response.requestid) {
                    currentRequestId = response.requestid;
                }
                setStatus(status, STATUS_CLASSES.info, response.message);
                generateButton.disabled = false;
                fetchStatus(true);
                startPolling();
            }, function(error) {
                var message = config.requestfailed;

                if (error && error.message) {
                    message += ' ' + error.message;
                }
                waitingForResult = false;
                stopRefreshTicker();
                setRefreshActivity(refreshInfo, config.refreshactivityidle);
                setStatus(status, STATUS_CLASSES.danger, message);
                generateButton.disabled = false;
            });
        });

        refreshButton.addEventListener('click', function(event) {
            event.preventDefault();
            fetchStatus(true);
        });

        document.addEventListener('visibilitychange', function() {
            if (document.hidden || !pollTimer) {
                return;
            }
            fetchStatus(false);
        });

        setRefreshActivity(refreshInfo, config.refreshactivityidle);
        fetchStatus(false);
    };

    return {
        init: init,
    };
});
