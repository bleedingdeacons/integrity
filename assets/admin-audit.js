/**
 * Integrity Audit Log — partial refresh of the request-log table.
 *
 * Modeled on Sentinel's log-viewer.js: XHR to admin-ajax.php, swap the
 * innerHTML of a single container instead of reloading the whole page.
 *
 * Differences from sentinel:
 *  - Refresh interval comes from the toggle's data-interval attribute
 *    (driven by the user-configurable plugin option), not a hard-coded value.
 *  - A live countdown is rendered next to the toggle while auto-refresh is on.
 *  - The active filter form is serialized and sent with each request so the
 *    refreshed log respects the filters/page the user is viewing.
 */
(function () {
    'use strict';

    if (typeof integrityAuditRefresh === 'undefined') {
        return;
    }

    var refreshBtn  = document.getElementById('integrity-refresh-btn');
    var toggle      = document.getElementById('integrity-auto-refresh');
    var countdownEl = document.getElementById('integrity-refresh-countdown');
    var container   = document.getElementById('integrity-audit-logs-container');
    var filtersForm = document.getElementById('integrity-audit-filters-form');

    if (!refreshBtn || !container) {
        return;
    }

    var interval = parseInt(toggle && toggle.getAttribute('data-interval'), 10) || 30;
    if (interval < 5) {
        interval = 5;
    }

    var timerId   = null;
    var remaining = interval;
    var inFlight  = false;

    /**
     * Build the form-encoded body for the AJAX request: action + nonce,
     * plus the current filter form fields (api_key_id, response_code,
     * ip_address, date_from, date_to, paged) so the server returns the
     * same view the user is looking at.
     */
    function buildBody() {
        var params = new URLSearchParams();
        params.append('action', integrityAuditRefresh.action);
        params.append('nonce', integrityAuditRefresh.nonce);

        if (filtersForm) {
            var data = new FormData(filtersForm);
            data.forEach(function (value, key) {
                // 'page' here is the WP admin page slug — not a pagination
                // index. Skip it; we don't need to send it to admin-ajax.
                if (key === 'page' || key === 'submit') {
                    return;
                }
                params.append(key, value);
            });
        }

        // Preserve current pagination position from the URL.
        var qs = new URLSearchParams(window.location.search);
        var paged = qs.get('paged');
        if (paged) {
            params.append('paged', paged);
        }

        return params.toString();
    }

    /**
     * Perform an AJAX refresh of just the logs container.
     *
     * @param {boolean} silent  If true, no spinner is shown (used by the
     *                          auto-refresh tick so the table doesn't blink).
     */
    function doRefresh(silent) {
        if (inFlight) {
            return;
        }
        inFlight = true;

        if (!silent) {
            refreshBtn.disabled = true;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', integrityAuditRefresh.url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            inFlight = false;
            refreshBtn.disabled = false;

            if (xhr.status !== 200) {
                return;
            }

            try {
                var response = JSON.parse(xhr.responseText);
                if (response && response.success && response.data && typeof response.data.html === 'string') {
                    container.innerHTML = response.data.html;
                }
            } catch (e) {
                // Swallow — leaving the existing table in place is the
                // least-disruptive failure mode for an auto-refresh.
            }
        };
        xhr.send(buildBody());
    }

    function renderCountdown() {
        if (!countdownEl) {
            return;
        }
        countdownEl.textContent = '(' + remaining + 's)';
    }

    function tick() {
        remaining -= 1;
        if (remaining <= 0) {
            renderCountdown();
            doRefresh(true);
            remaining = interval;
            return;
        }
        renderCountdown();
    }

    function startAutoRefresh() {
        stopAutoRefresh();
        remaining = interval;
        renderCountdown();
        timerId = setInterval(tick, 1000);
    }

    function stopAutoRefresh() {
        if (timerId !== null) {
            clearInterval(timerId);
            timerId = null;
        }
        if (countdownEl) {
            countdownEl.textContent = '';
        }
    }

    /* Manual refresh */
    refreshBtn.addEventListener('click', function () {
        doRefresh(false);
        // Reset the countdown so the next auto-tick is a full interval away.
        if (toggle && toggle.checked) {
            remaining = interval;
            renderCountdown();
        }
    });

    /* Auto-refresh toggle */
    if (toggle) {
        toggle.addEventListener('change', function () {
            if (toggle.checked) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });

        if (toggle.checked) {
            startAutoRefresh();
        }
    }
})();
