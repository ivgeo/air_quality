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
    const sortButtons = Array.from(document.querySelectorAll('[data-sort]'));
    const themeToggle = document.getElementById('theme-toggle');
    const status = document.getElementById('status');
    const alert = document.getElementById('alert');
    const result = document.getElementById('result');
    const tbody = document.getElementById('ranking-body');
    const fieldErrors = {
        from: { input: fromInput, error: document.getElementById('from-error') },
        to: { input: toInput, error: document.getElementById('to-error') },
        metric: { input: null, error: document.getElementById('metric-error') },
    };

    const FAILURE = 'Could not load the ranking. Try again.';

    const state = {
        metric: app.dataset.defaultMetric,
        request: null,
        rows: [],
        meta: null,
        // The API order is the ranking itself: sorting by `#` ascending shows it unchanged.
        sort: { key: 'rank', dir: 'asc' },
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

    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Y-m-d as "17 Oct 2026". */
    function formatDate(date) {
        const [year, month, day] = date.split('-').map(Number);

        return day + ' ' + MONTHS[month - 1] + ' ' + year;
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

    const comparators = {
        rank: function (a, b) {
            return a.rank - b.rank;
        },
        city: function (a, b) {
            return a.city.name.localeCompare(b.city.name, 'en');
        },
        average: function (a, b) {
            return a.average - b.average;
        },
        coverage: function (a, b) {
            return a.days_with_data - b.days_with_data || a.measurements - b.measurements;
        },
    };

    /** A sorted copy of the rows; ties keep the ranking order whatever the direction. */
    function sortRows(rows, sort) {
        const direction = sort.dir === 'asc' ? 1 : -1;

        return rows.slice().sort(function (a, b) {
            return direction * comparators[sort.key](a, b) || a.rank - b.rank;
        });
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
        showLoading();

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

                finish();

                if (response.status === 200 && Array.isArray(response.body.data)) {
                    showRanking(response.body);
                } else if (response.status === 422 && response.body.errors) {
                    showValidationErrors(response.body);
                } else {
                    showMessage(alert, FAILURE);
                }
            })
            .catch(function (error) {
                // A network error, an invalid JSON body or a newer request aborting this one.
                if (error.name === 'AbortError' || request !== state.request) {
                    return;
                }

                finish();
                showMessage(alert, FAILURE);
            });
    }

    function finish() {
        state.request = null;
        app.removeAttribute('aria-busy');
        status.hidden = true;
    }

    // ---- States ------------------------------------------------------------------------------

    /** Hides the previous result and errors, so nothing stale looks current while loading. */
    function showLoading() {
        clearErrors();
        result.hidden = true;
        app.setAttribute('aria-busy', 'true');
        showMessage(status, 'Loading…');
    }

    function showMessage(target, text) {
        target.textContent = text;
        target.hidden = false;
    }

    function clearErrors() {
        alert.hidden = true;
        Object.keys(fieldErrors).forEach(function (field) {
            const entry = fieldErrors[field];
            entry.error.hidden = true;
            entry.error.textContent = '';

            if (entry.input) {
                entry.input.removeAttribute('aria-invalid');
                entry.input.removeAttribute('aria-describedby');
            }
        });
    }

    /** Shows each API validation error at its field, and the summary above the result. */
    function showValidationErrors(body) {
        Object.keys(body.errors).forEach(function (field) {
            const entry = fieldErrors[field];

            if (!entry) {
                return;
            }

            showMessage(entry.error, body.errors[field].join(' '));

            if (entry.input) {
                entry.input.setAttribute('aria-invalid', 'true');
                entry.input.setAttribute('aria-describedby', entry.error.id);
            }
        });
        showMessage(alert, body.message || FAILURE);
    }

    function showRanking(body) {
        if (body.data.length === 0) {
            state.rows = [];
            state.meta = null;
            showMessage(status, 'No data for this period.');

            return;
        }

        state.rows = body.data;
        state.meta = body.meta;
        render(body);
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
        document.getElementById('average-label').textContent = 'Average ' + meta.metric.label;
        document.getElementById('average-unit').textContent = meta.metric.unit;

        renderTable();
        result.hidden = false;
    }

    function renderTable() {
        tbody.replaceChildren.apply(tbody, sortRows(state.rows, state.sort).map(function (row) {
            return renderRow(row, state.meta);
        }));
        sortButtons.forEach(function (button) {
            const active = button.dataset.sort === state.sort.key;
            button.closest('th').setAttribute(
                'aria-sort',
                active ? (state.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none',
            );
        });
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

    // ---- Theme -------------------------------------------------------------------------------

    const darkScheme = window.matchMedia('(prefers-color-scheme: dark)');

    function currentTheme() {
        return document.documentElement.dataset.theme || (darkScheme.matches ? 'dark' : 'light');
    }

    function updateThemeToggle() {
        themeToggle.setAttribute('aria-label', 'Switch to ' + (currentTheme() === 'dark' ? 'light' : 'dark') + ' theme');
    }

    function toggleTheme() {
        const theme = currentTheme() === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = theme;

        try {
            localStorage.setItem('theme', theme);
        } catch (e) {
            // Storage blocked (e.g. a private window): the choice lasts until the page is reloaded.
        }

        updateThemeToggle();
    }

    // ---- Events ------------------------------------------------------------------------------

    themeToggle.addEventListener('click', toggleTheme);
    darkScheme.addEventListener('change', updateThemeToggle);

    sortButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const key = button.dataset.sort;
            const dir = key === state.sort.key
                ? (state.sort.dir === 'asc' ? 'desc' : 'asc')
                : (key === 'coverage' ? 'desc' : 'asc');

            state.sort = { key: key, dir: dir };
            renderTable();
        });
    });

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
    updateThemeToggle();
    load();
}());
