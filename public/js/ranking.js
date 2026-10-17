/*
 * Clean Air Finder ranking page: a thin client of GET /api/v1/ranking.
 *
 * Every number on the page comes from the API response; the page only picks the period and the
 * metric, and renders what it gets back. No build step and no dependencies.
 */
(function () {
    'use strict';

    const DEFAULT_DAYS = 30;

    const app = document.getElementById('app');
    const apiUrl = app.dataset.apiUrl;
    // Today in Europe/Sofia, from the server: the quick periods must not follow the browser's time zone.
    const today = app.dataset.today;

    const form = document.getElementById('period-form');
    const fromInput = document.getElementById('from');
    const toInput = document.getElementById('to');
    const periodButtons = Array.from(document.querySelectorAll('[data-days]'));
    const metricButtons = Array.from(document.querySelectorAll('[data-metric]'));
    const result = document.getElementById('result');
    const tbody = document.getElementById('ranking-body');

    const state = {
        metric: app.dataset.defaultMetric,
        request: null,
    };

    // ---- Pure helpers ------------------------------------------------------------------------

    /** Adds whole days to a Y-m-d date in UTC, so DST never shifts the result. */
    function addDays(date, days) {
        const [year, month, day] = date.split('-').map(Number);

        return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
    }

    /** The last `days` calendar days up to and including `end`. */
    function lastDays(days, end) {
        return { from: addDays(end, -(days - 1)), to: end };
    }

    const dateFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

    function formatDate(date) {
        const [year, month, day] = date.split('-').map(Number);

        return dateFormat.format(new Date(Date.UTC(year, month - 1, day)));
    }

    function formatRange(range) {
        return range.from === range.to ? formatDate(range.from) : formatDate(range.from) + ' – ' + formatDate(range.to);
    }

    function formatAverage(value) {
        return Number(value).toFixed(2);
    }

    function plural(count, word) {
        return count + ' ' + word + (count === 1 ? '' : 's');
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

    // ---- Controls ----------------------------------------------------------------------------

    function pressOnly(buttons, pressed) {
        buttons.forEach(function (button) {
            button.setAttribute('aria-pressed', String(button === pressed));
        });
    }

    function selectPeriod(button) {
        pressOnly(periodButtons, button);

        if (button.dataset.days !== 'custom') {
            const period = lastDays(Number(button.dataset.days), today);
            fromInput.value = period.from;
            toInput.value = period.to;
        }
    }

    function selectMetric(slug) {
        state.metric = slug;
        pressOnly(metricButtons, metricButtons.find(function (button) {
            return button.dataset.metric === slug;
        }));
    }

    // ---- Request -----------------------------------------------------------------------------

    function load() {
        if (state.request) {
            state.request.abort();
        }

        const request = new AbortController();
        state.request = request;

        const params = new URLSearchParams({ from: fromInput.value, to: toInput.value });

        if (state.metric) {
            params.set('metric', state.metric);
        }

        fetch(apiUrl + '?' + params.toString(), { headers: { Accept: 'application/json' }, signal: request.signal })
            .then(function (response) {
                return response.json().then(function (body) {
                    return { status: response.status, body: body };
                });
            })
            .then(function (response) {
                if (request !== state.request) {
                    return;
                }

                state.request = null;

                if (response.status === 200) {
                    render(response.body);
                }
            })
            .catch(function (error) {
                if (error.name === 'AbortError') {
                    return;
                }
            });
    }

    // ---- Rendering ---------------------------------------------------------------------------

    function render(body) {
        const meta = body.meta;
        const first = body.data[0];

        document.getElementById('card-city').textContent = first.city.name;
        document.getElementById('card-city-average').textContent =
            meta.metric.label + ' · ' + formatAverage(first.average) + ' ' + meta.metric.unit;
        document.getElementById('card-period-days').textContent = plural(meta.period.days, 'day');
        document.getElementById('card-period-range').textContent = formatRange(meta.period);
        document.getElementById('card-stored-range').textContent = formatRange(meta.stored);
        document.getElementById('card-stored-days').textContent = 'Any period inside it can be ranked';
        document.getElementById('average-label').textContent = 'Average ' + meta.metric.label;
        document.getElementById('average-unit').textContent = meta.metric.unit;

        tbody.replaceChildren.apply(tbody, body.data.map(function (row) {
            return renderRow(row, meta);
        }));
        result.hidden = false;
    }

    function renderRow(row, meta) {
        const tr = element('tr', row.rank === 1 ? 'is-first' : '');

        tr.append(
            element('td', '', String(row.rank)),
            element('td', 'city', row.city.name),
            element('td', 'average', formatAverage(row.average)),
            renderCoverage(row, meta.period.days),
            element('td', '', formatRange(row.stored)),
        );

        return tr;
    }

    function renderCoverage(row, periodDays) {
        const cell = element('td');
        const partial = row.days_with_data < periodDays;
        const coverage = element('div', 'coverage' + (partial ? ' is-partial' : ''));
        const bar = element('div', 'coverage__bar');
        const fill = element('div', 'coverage__fill');
        const text = element('div', 'coverage__text');

        fill.style.width = Math.round(row.days_with_data / periodDays * 100) + '%';
        bar.append(fill);
        text.append(
            element('span', 'coverage__days', row.days_with_data + ' / ' + plural(periodDays, 'day')),
            element('small', '', plural(row.measurements, 'measurement')),
        );
        coverage.append(bar, text);
        cell.append(coverage);

        if (partial) {
            cell.title = 'No data on ' + plural(periodDays - row.days_with_data, 'day') + ' of the period';
        }

        return cell;
    }

    // ---- Events ------------------------------------------------------------------------------

    periodButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            selectPeriod(button);

            if (button.dataset.days === 'custom') {
                fromInput.focus();

                return;
            }

            load();
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        selectPeriod(periodButtons.find(function (button) {
            return button.dataset.days === 'custom';
        }));
        load();
    });

    metricButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            selectMetric(button.dataset.metric);
            load();
        });
    });

    selectPeriod(periodButtons.find(function (button) {
        return button.dataset.days === String(DEFAULT_DAYS);
    }));
    selectMetric(state.metric);
    load();
}());
