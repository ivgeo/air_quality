@extends('layouts.app')

@section('title', 'Clean Air Finder')

@push('scripts')
    <script src="{{ asset('js/ranking.js') }}?v={{ filemtime(public_path('js/ranking.js')) }}" defer></script>
@endpush

@section('content')
<div
    id="app"
    data-api-url="{{ route('api.v1.ranking') }}"
    data-today="{{ $today }}"
    data-default-metric="{{ $defaultMetric?->value }}"
>
    <main class="container page">
        <div class="page__heading">
            <div class="page__title">
                <h1>Cleanest air in Bulgaria</h1>
                <p>Cities ranked by their average pollution over the selected period, the cleanest first.</p>
            </div>
            <div class="segmented" role="group" aria-label="Period">
                <button type="button" data-days="7" aria-pressed="false">7 days</button>
                <button type="button" data-days="30" aria-pressed="false">30 days</button>
                <button type="button" data-days="90" aria-pressed="false">3 months</button>
                <button type="button" data-days="180" aria-pressed="false">6 months</button>
                <button type="button" data-days="custom" aria-pressed="false">Custom</button>
            </div>
        </div>

        <section class="panel filters" aria-label="Filters">
            <form id="period-form" class="filters__period" novalidate>
                <div class="field">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" max="{{ $today }}" required>
                    <p class="field__error" id="from-error" hidden></p>
                </div>
                <div class="field">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" max="{{ $today }}" required>
                    <p class="field__error" id="to-error" hidden></p>
                </div>
                <button type="submit" class="button">Apply</button>
            </form>
            <div class="filters__metric">
                <span id="metric-label" class="filters__label">Metric</span>
                <div class="chips" role="group" aria-labelledby="metric-label" aria-describedby="metric-error">
                    @foreach ($metrics as $metric)
                        <button type="button" class="chip" data-metric="{{ $metric->value }}" aria-pressed="false">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                            <span>{{ $metric->label() }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="field__error" id="metric-error" hidden></p>
            </div>
        </section>

        <div id="status" class="notice" role="status" aria-live="polite" hidden></div>
        <div id="alert" class="notice notice--error" role="alert" hidden></div>

        <div id="result" class="result" hidden>
            <div id="cards" class="cards">
                <div class="card">
                    <span class="card__label">Cleanest city</span>
                    <span class="card__value" id="card-city"></span>
                    <span class="card__hint card__hint--good" id="card-city-average"></span>
                </div>
                <div class="card">
                    <span class="card__label">Period</span>
                    <span class="card__value" id="card-period-days"></span>
                    <span class="card__hint" id="card-period-range"></span>
                </div>
                <div class="card">
                    <span class="card__label">Available data</span>
                    <span class="card__value card__value--small" id="card-stored-range"></span>
                    <span class="card__hint">Any period inside it can be ranked</span>
                </div>
            </div>

            <div class="table-wrap">
                <table id="ranking-table" class="ranking">
                    <caption class="visually-hidden">Cities ranked by average pollution over the period</caption>
                    <thead>
                        <tr>
                            <th scope="col" aria-sort="ascending"><button type="button" class="sort" data-sort="rank">#</button></th>
                            <th scope="col" aria-sort="none"><button type="button" class="sort" data-sort="city">City</button></th>
                            <th scope="col" aria-sort="none" class="num">
                                <button type="button" class="sort" data-sort="average">
                                    <span class="sort__label"><span id="average-label">Average</span><small id="average-unit"></small></span>
                                </button>
                            </th>
                            <th scope="col" aria-sort="none"><button type="button" class="sort" data-sort="coverage">Coverage</button></th>
                            <th scope="col" class="plain">Available data</th>
                        </tr>
                    </thead>
                    <tbody id="ranking-body"></tbody>
                </table>
            </div>
        </div>

        <p class="footnote">
            Averages of the stored hourly measurements of each city, weighted by the number of measurements.
            Coverage shows on how many days of the period a city has data. Dates are calendar days in Europe/Sofia.
        </p>
    </main>
</div>
@endsection
