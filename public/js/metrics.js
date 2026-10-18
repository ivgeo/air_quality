/*
 * Clean Air Finder metrics page: a thin client of /health and GET /api/v1/metrics.
 *
 * Every value comes from those two responses; the page only derives ratios and relative times
 * from them. Charts are hand-drawn inline SVG coloured by the theme's CSS variables. No build
 * step and no dependencies.
 */
(function () {
    'use strict';

    const REFRESH_MS = 30000;
    const SVG_NS = 'http://www.w3.org/2000/svg';
    const BAR_HEIGHT = 24;
    const GAP = 2;
    const RADIUS = 4;

    const root = document.getElementById('metrics');
    const content = document.getElementById('content');
    const alert = document.getElementById('alert');
    const updated = document.getElementById('updated');
    const refreshButton = document.getElementById('refresh');
    const tooltip = document.getElementById('tooltip');

    const number = new Intl.NumberFormat('en');
    const time = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const dateTime = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

    const OUTCOMES = [
        { key: 'success', label: 'Success', color: '--chart-good' },
        { key: 'failed', label: 'Failed', color: '--chart-bad' },
        { key: 'running', label: 'Running', color: '--chart-neutral' },
    ];
    const ROWS = [
        { key: 'rows_written', label: 'Written', color: '--chart-good' },
        { key: 'rows_rejected', label: 'Rejected', color: '--chart-warn' },
    ];
    const STATUSES = [
        { key: '2xx', label: '2xx', color: '--chart-good' },
        { key: '3xx', label: '3xx', color: '--chart-neutral' },
        { key: '4xx', label: '4xx', color: '--chart-warn' },
        { key: '5xx', label: '5xx', color: '--chart-bad' },
    ];
    const BADGES = {
        ok: { text: 'OK', tone: 'good' },
        degraded: { text: 'Degraded', tone: 'warn' },
        down: { text: 'Down', tone: 'bad' },
        stale: { text: 'Stale', tone: 'warn' },
        unavailable: { text: 'Unavailable', tone: 'warn' },
        success: { text: 'Success', tone: 'good' },
        failed: { text: 'Failed', tone: 'bad' },
        running: { text: 'Running', tone: 'neutral' },
    };

    const state = { request: null, timer: null, charts: {} };

    // ---- Pure helpers ------------------------------------------------------------------------

    function sum(values) {
        return values.reduce(function (total, value) {
            return total + value;
        }, 0);
    }

    function runCount(block) {
        return block.runs.success + block.runs.failed + block.runs.running;
    }

    /** A share as "12.3%", or null when there is nothing to divide. */
    function percent(part, whole) {
        return whole > 0 ? (part / whole * 100).toFixed(1) + '%' : null;
    }

    function duration(ms) {
        if (ms === null) {
            return '—';
        }

        return ms < 1000 ? number.format(ms) + ' ms' : (ms / 1000).toFixed(1) + ' s';
    }

    function latency(ms) {
        return ms === null ? '—' : ms.toFixed(1) + ' ms';
    }

    /** "5 min ago", "3 h ago", "2 days ago" relative to now. */
    function ago(iso) {
        const minutes = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 60000));

        if (minutes < 1) {
            return 'just now';
        }

        if (minutes < 60) {
            return minutes + ' min ago';
        }

        const hours = Math.round(minutes / 60);

        return hours < 48 ? hours + ' h ago' : Math.round(hours / 24) + ' days ago';
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function svg(tag, attributes) {
        const node = document.createElementNS(SVG_NS, tag);

        Object.keys(attributes).forEach(function (name) {
            node.setAttribute(name, attributes[name]);
        });

        return node;
    }

    function badge(status) {
        const config = BADGES[status] || { text: status, tone: 'neutral' };

        return { text: config.text, className: 'badge badge--' + config.tone };
    }

    function setBadge(id, status) {
        const target = document.getElementById(id);
        const config = badge(status);
        target.className = config.className;
        target.textContent = config.text;
    }

    function setText(id, text) {
        document.getElementById(id).textContent = text;
    }

    // ---- Requests ----------------------------------------------------------------------------

    /** Resolves with the parsed body when the status is expected, rejects otherwise. */
    function fetchJson(url, signal, okStatuses) {
        return fetch(url, { headers: { Accept: 'application/json' }, signal: signal }).then(function (response) {
            if (okStatuses.indexOf(response.status) === -1) {
                throw new Error(url + ' responded with HTTP ' + response.status);
            }

            return response.json();
        });
    }

    function load() {
        if (state.request) {
            state.request.abort();
        }

        const request = new AbortController();
        state.request = request;
        content.setAttribute('aria-busy', 'true');
        scheduleRefresh();

        // /health answers 503 with a full body when the database is down.
        const health = fetchJson(root.dataset.healthUrl, request.signal, [200, 503]);
        const metrics = fetchJson(root.dataset.metricsUrl, request.signal, [200]);

        Promise.allSettled([health, metrics]).then(function (results) {
            if (request !== state.request) {
                return;
            }

            state.request = null;
            content.removeAttribute('aria-busy');

            const healthBody = results[0].status === 'fulfilled' ? results[0].value : null;
            const metricsBody = results[1].status === 'fulfilled' ? results[1].value.data : null;

            if (healthBody) {
                renderHealth(healthBody);
            }

            if (metricsBody) {
                renderMetrics(metricsBody);
            }

            showOutcome(healthBody, metricsBody);
        });
    }

    /** Refresh 30 seconds after the latest load, whether automatic or from the button. */
    function scheduleRefresh() {
        clearTimeout(state.timer);
        state.timer = setTimeout(load, REFRESH_MS);
    }

    /**
     * Anything not fully current (database down, an endpoint failing) gets a message, and the
     * values left from an earlier load are dimmed so they never pass for current ones.
     */
    function showOutcome(health, metrics) {
        /** @type {string|null} */
        let problem = null;

        if (health && health.status === 'down') {
            problem = 'The database is unavailable: the application cannot serve data right now.';
        } else if (!health || !metrics) {
            problem = 'Could not load ' + (!health && !metrics ? 'the health check and the metrics' : (!health ? 'the health check' : 'the metrics')) + '.';
        }

        if (problem) {
            const loadedBefore = content.dataset.loaded === 'true';
            alert.textContent = problem + (loadedBefore ? ' The values below are from the last successful update.' : '');
            alert.hidden = false;
            content.classList.add('is-stale');
            updated.textContent = 'Update failed at ' + time.format(new Date());

            return;
        }

        alert.hidden = true;
        content.classList.remove('is-stale');
        content.dataset.loaded = 'true';
        updated.textContent = 'Updated ' + time.format(new Date());
    }

    // ---- Health ------------------------------------------------------------------------------

    function renderHealth(health) {
        setBadge('health-status', health.status);
        setBadge('check-database', health.checks.database.status);
        setBadge('check-store', health.checks.metrics_store.status);

        const freshness = health.checks.freshness;
        const list = document.getElementById('freshness');

        if (!freshness) {
            setText('check-threshold', '—');
            list.replaceChildren(element('li', 'freshness__empty', 'Freshness cannot be checked without the database.'));

            return;
        }

        setText('check-threshold', freshness.threshold_hours + ' h since the last successful run');

        if (freshness.providers.length === 0) {
            list.replaceChildren(element('li', 'freshness__empty', 'No active provider.'));

            return;
        }

        list.replaceChildren.apply(list, freshness.providers.map(function (provider) {
            const item = element('li', 'freshness__item');
            const config = badge(provider.status);
            const last = provider.last_success_at;

            item.append(
                element('span', 'freshness__name', provider.name),
                element('span', config.className, config.text),
                element('span', 'freshness__when', last
                    ? 'Last successful run ' + ago(last) + ' · ' + dateTime.format(new Date(last))
                    : 'No successful run yet'),
            );

            return item;
        }));
    }

    // ---- Metrics -----------------------------------------------------------------------------

    function renderMetrics(data) {
        const total = data.ingestion.total;
        const recent = data.ingestion.last_24h;
        const recentRuns = runCount(recent);
        const totalRuns = runCount(total);
        const finishedRecent = recent.runs.success + recent.runs.failed;
        const recentRows = recent.rows_written + recent.rows_rejected;

        setText('runs-recent', number.format(recentRuns));
        setText('runs-recent-hint', successHint(recent.runs.success, finishedRecent));
        setText('runs-total', number.format(totalRuns));
        setText('runs-total-hint', successHint(total.runs.success, total.runs.success + total.runs.failed));
        setText('rows-recent', number.format(recent.rows_written));
        setText('rows-recent-hint', recentRows > 0
            ? percent(recent.rows_rejected, recentRows) + ' rejected · ' + number.format(Math.round(recent.rows_written / Math.max(1, recent.runs.success))) + ' per successful run'
            : 'No rows in the last 24 hours');
        setText('duration-recent', duration(recent.avg_duration_ms));
        setText('duration-total', 'All time: ' + duration(total.avg_duration_ms));

        renderChart('chart-runs', OUTCOMES, [
            { label: 'Last 24 hours', values: recent.runs },
            { label: 'All time', values: total.runs },
        ], 'runs');
        renderChart('chart-rows', ROWS, [
            { label: 'Last 24 hours', values: recent },
            { label: 'All time', values: total },
        ], 'rows');

        renderLastRuns(data.ingestion.last_run_by_provider);
        renderFailures(data.ingestion.recent_failures);
        renderApi(data.api);
    }

    function successHint(success, finished) {
        return finished > 0 ? percent(success, finished) + ' successful' : 'No finished runs';
    }

    function renderApi(api) {
        document.getElementById('api-unavailable').hidden = api !== null;

        if (api === null) {
            ['api-requests', 'api-errors', 'api-avg', 'api-max'].forEach(function (id) {
                setText(id, '—');
            });
            setText('api-error-rate', '');
            renderChart('chart-api', STATUSES, [], 'requests');

            return;
        }

        setText('api-requests', number.format(api.requests));
        setText('api-errors', number.format(api.errors));
        setText('api-error-rate', api.requests > 0 ? percent(api.errors, api.requests) + ' of requests' : 'No requests yet');
        setText('api-avg', latency(api.avg_latency_ms));
        setText('api-max', latency(api.max_latency_ms));
        renderChart('chart-api', STATUSES, [{ label: 'All requests', values: api.by_status }], 'requests');
    }

    function renderLastRuns(runs) {
        const tbody = document.getElementById('last-runs');

        if (runs.length === 0) {
            tbody.replaceChildren(emptyRow(6, 'No runs yet.'));

            return;
        }

        tbody.replaceChildren.apply(tbody, runs.map(function (run) {
            const tr = element('tr');
            const outcome = element('td');
            const config = badge(run.outcome);
            outcome.append(element('span', config.className, config.text));

            tr.append(
                element('td', 'strong', run.provider.name),
                element('td', '', run.city.name),
                outcome,
                timeCell(run.started_at),
                element('td', 'num', number.format(run.rows_written)),
                element('td', 'num', number.format(run.rows_rejected)),
            );

            return tr;
        }));
    }

    function renderFailures(failures) {
        const tbody = document.getElementById('failures');

        if (failures.length === 0) {
            tbody.replaceChildren(emptyRow(4, 'No failed runs.'));

            return;
        }

        tbody.replaceChildren.apply(tbody, failures.map(function (failure) {
            const tr = element('tr');

            tr.append(
                timeCell(failure.started_at),
                element('td', 'strong', failure.provider.name),
                element('td', '', failure.city.name),
                element('td', 'error', failure.error || '—'),
            );

            return tr;
        }));
    }

    function timeCell(iso) {
        const cell = element('td', 'nowrap', dateTime.format(new Date(iso)));
        cell.append(element('small', '', ago(iso)));

        return cell;
    }

    function emptyRow(columns, text) {
        const tr = element('tr');
        const td = element('td', 'empty', text);
        td.colSpan = columns;
        tr.append(td);

        return tr;
    }

    // ---- Charts ------------------------------------------------------------------------------

    /**
     * One 100% stacked bar per row (part-to-whole), each with its values listed under it, so the
     * numbers never depend on colour or on the tooltip.
     */
    function renderChart(id, series, rows, unit) {
        state.charts[id] = { series: series, rows: rows, unit: unit };
        drawChart(id);
    }

    function drawChart(id) {
        const figure = document.getElementById(id);
        const chart = state.charts[id];
        const caption = figure.querySelector('figcaption');

        figure.replaceChildren(caption);

        if (chart.rows.length === 0) {
            figure.append(element('p', 'chart__empty', 'No data.'));

            return;
        }

        chart.rows.forEach(function (row) {
            const total = sum(chart.series.map(function (item) {
                return row.values[item.key];
            }));
            const block = element('div', 'chart__row');

            block.append(element('div', 'chart__label', row.label + ' · ' + number.format(total) + ' ' + chart.unit));
            figure.append(block);
            block.append(bar(chart.series, row, total, block.clientWidth));
            block.append(legend(chart.series, row.values, total));
        });
    }

    function bar(series, row, total, width) {
        const node = svg('svg', { width: width, height: BAR_HEIGHT, role: 'img', 'aria-label': row.label });
        const visible = series.filter(function (item) {
            return row.values[item.key] > 0;
        });

        if (total === 0) {
            node.append(svg('rect', { x: 0, y: 0, width: width, height: BAR_HEIGHT, rx: RADIUS, class: 'chart__track' }));

            return node;
        }

        // Every segment keeps at least 2px, so a tiny share stays visible (and hoverable).
        const space = width - GAP * (visible.length - 1);
        let x = 0;

        visible.forEach(function (item, index) {
            const value = row.values[item.key];
            const last = index === visible.length - 1;
            const w = last ? width - x : Math.max(2, Math.round(value / total * space));
            const segment = svg('path', {
                d: last ? roundedEnd(x, w) : 'M' + x + ' 0h' + w + 'v' + BAR_HEIGHT + 'h' + -w + 'z',
                fill: 'var(' + item.color + ')',
                class: 'chart__segment',
                tabindex: '0',
                'aria-label': item.label + ': ' + number.format(value),
            });

            bindTooltip(segment, item.label, number.format(value) + ' (' + percent(value, total) + ')');
            node.append(segment);
            x += w + GAP;
        });

        return node;
    }

    /** A segment square at its start and rounded at the bar's end. */
    function roundedEnd(x, w) {
        const r = Math.min(RADIUS, w);

        return 'M' + x + ' 0h' + (w - r) + 'a' + r + ' ' + r + ' 0 0 1 ' + r + ' ' + r
            + 'v' + (BAR_HEIGHT - 2 * r) + 'a' + r + ' ' + r + ' 0 0 1 ' + -r + ' ' + r
            + 'h' + -(w - r) + 'z';
    }

    function legend(series, values, total) {
        const list = element('ul', 'chart__legend');

        series.forEach(function (item) {
            const entry = element('li');
            const swatch = element('span', 'chart__swatch');
            swatch.style.background = 'var(' + item.color + ')';

            entry.append(
                swatch,
                element('span', '', item.label),
                element('strong', '', number.format(values[item.key])),
            );

            if (total > 0) {
                entry.append(element('small', '', percent(values[item.key], total)));
            }

            list.append(entry);
        });

        return list;
    }

    function bindTooltip(target, label, value) {
        function show() {
            const box = target.getBoundingClientRect();
            tooltip.replaceChildren(element('strong', '', value), element('span', '', label));
            tooltip.hidden = false;
            tooltip.style.left = Math.min(window.innerWidth - tooltip.offsetWidth - 8, Math.max(8, box.left + box.width / 2 - tooltip.offsetWidth / 2)) + 'px';
            tooltip.style.top = (box.top + window.scrollY - tooltip.offsetHeight - 8) + 'px';
        }

        function hide() {
            tooltip.hidden = true;
        }

        target.addEventListener('pointerenter', show);
        target.addEventListener('focus', show);
        target.addEventListener('pointerleave', hide);
        target.addEventListener('blur', hide);
    }

    // ---- Events ------------------------------------------------------------------------------

    refreshButton.addEventListener('click', load);

    // Bars are drawn in pixels: redraw them when their width changes.
    let resizeFrame = 0;
    window.addEventListener('resize', function () {
        cancelAnimationFrame(resizeFrame);
        resizeFrame = requestAnimationFrame(function () {
            Object.keys(state.charts).forEach(drawChart);
        });
    });

    load();
}());
