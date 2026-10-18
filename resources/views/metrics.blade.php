@extends('layouts.app')

@section('title', 'Metrics · Clean Air Finder')

@push('scripts')
    <script src="{{ asset('js/metrics.js') }}?v={{ filemtime(public_path('js/metrics.js')) }}" defer></script>
@endpush

@section('content')
<main
    id="metrics"
    class="container page"
    data-health-url="{{ route('health') }}"
    data-metrics-url="{{ route('api.v1.metrics') }}"
>
    <div class="page__heading">
        <div class="page__title">
            <h1>Metrics</h1>
            <p>Whether the application and its data collection are healthy. Refreshes every 30 seconds.</p>
        </div>
        <div class="toolbar">
            <span class="toolbar__updated" id="updated" role="status" aria-live="polite">Loading…</span>
            <button type="button" class="button button--quiet" id="refresh">Refresh</button>
        </div>
    </div>

    <div id="alert" class="notice notice--error" role="alert" hidden></div>

    <div id="content" class="metrics" aria-busy="true">
        <section id="health" class="panel health" aria-labelledby="health-title">
            <div class="health__head">
                <h2 id="health-title">Health</h2>
                <span class="badge" id="health-status">—</span>
            </div>
            <dl class="health__checks">
                <div>
                    <dt>Database</dt>
                    <dd><span class="badge" id="check-database">—</span></dd>
                </div>
                <div>
                    <dt>Metrics store (Redis)</dt>
                    <dd><span class="badge" id="check-store">—</span></dd>
                </div>
                <div>
                    <dt>Freshness threshold</dt>
                    <dd id="check-threshold">—</dd>
                </div>
            </dl>
            <ul class="freshness" id="freshness" aria-label="Data freshness per provider"></ul>
        </section>

        <section class="metrics__section" aria-labelledby="ingestion-title">
            <h2 id="ingestion-title">Data collection</h2>
            <div class="cards">
                <div class="card">
                    <span class="card__label">Runs in the last 24 hours</span>
                    <span class="card__value" id="runs-recent">—</span>
                    <span class="card__hint" id="runs-recent-hint"></span>
                </div>
                <div class="card">
                    <span class="card__label">Runs in total</span>
                    <span class="card__value" id="runs-total">—</span>
                    <span class="card__hint" id="runs-total-hint"></span>
                </div>
                <div class="card">
                    <span class="card__label">Rows written in the last 24 hours</span>
                    <span class="card__value" id="rows-recent">—</span>
                    <span class="card__hint" id="rows-recent-hint"></span>
                </div>
                <div class="card">
                    <span class="card__label">Average run duration, last 24 hours</span>
                    <span class="card__value" id="duration-recent">—</span>
                    <span class="card__hint" id="duration-total"></span>
                </div>
            </div>
            <div class="charts">
                <figure class="panel chart" id="chart-runs">
                    <figcaption class="chart__title">Runs by outcome</figcaption>
                </figure>
                <figure class="panel chart" id="chart-rows">
                    <figcaption class="chart__title">Rows written and rejected</figcaption>
                </figure>
            </div>

            <h3>Last run of each provider</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Provider</th>
                            <th scope="col">City</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Started</th>
                            <th scope="col" class="num">Rows written</th>
                            <th scope="col" class="num">Rejected</th>
                        </tr>
                    </thead>
                    <tbody id="last-runs"></tbody>
                </table>
            </div>

            <h3>Recent failures</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Started</th>
                            <th scope="col">Provider</th>
                            <th scope="col">City</th>
                            <th scope="col">Error</th>
                        </tr>
                    </thead>
                    <tbody id="failures"></tbody>
                </table>
            </div>
        </section>

        <section class="metrics__section" aria-labelledby="api-title">
            <h2 id="api-title">API</h2>
            <div id="api-unavailable" class="notice" hidden>
                API metrics unavailable: the metrics store does not answer. The API itself keeps working.
            </div>
            <div class="cards">
                <div class="card">
                    <span class="card__label">Requests</span>
                    <span class="card__value" id="api-requests">—</span>
                    <span class="card__hint">Under /api, except the metrics endpoint</span>
                </div>
                <div class="card">
                    <span class="card__label">Errors (4xx and 5xx)</span>
                    <span class="card__value" id="api-errors">—</span>
                    <span class="card__hint" id="api-error-rate"></span>
                </div>
                <div class="card">
                    <span class="card__label">Average latency</span>
                    <span class="card__value" id="api-avg">—</span>
                    <span class="card__hint">Measured in the application</span>
                </div>
                <div class="card">
                    <span class="card__label">Maximum latency</span>
                    <span class="card__value" id="api-max">—</span>
                    <span class="card__hint">Slowest request so far</span>
                </div>
            </div>
            <div class="charts">
                <figure class="panel chart" id="chart-api">
                    <figcaption class="chart__title">Responses by status</figcaption>
                </figure>
            </div>
        </section>
    </div>

    <p class="footnote">
        Read from <a href="{{ route('health') }}">/health</a> and <a href="{{ url('/docs/api') }}">GET /api/v1/metrics</a>.
        Times are shown in your browser's time zone.
    </p>
</main>
<div class="tooltip" id="tooltip" role="tooltip" hidden></div>
@endsection
