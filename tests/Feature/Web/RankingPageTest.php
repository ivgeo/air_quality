<?php

namespace Tests\Feature\Web;

use App\Enums\Metric;
use App\Models\QualityMetric;
use Database\Seeders\QualityMetricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RankingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(QualityMetricSeeder::class);
        $this->travelTo('2026-07-20 12:00:00');
    }

    public function test_the_page_renders_the_ranking_view_without_any_daily_data(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('ranking')
            ->assertSee('<title>Clean Air Finder</title>', false)
            ->assertSee('id="cards"', false)
            ->assertSee('id="ranking-table"', false);
    }

    public function test_the_active_metrics_are_offered_in_the_order_of_the_enum(): void
    {
        $this->get('/')
            ->assertViewHas('metrics', [Metric::Pm25, Metric::Pm10])
            ->assertViewHas('defaultMetric', Metric::Pm25)
            ->assertSeeInOrder(['data-metric="pm25"', 'PM2.5', 'data-metric="pm10"', 'PM10'], false)
            ->assertSee('data-default-metric="pm25"', false);
    }

    public function test_an_inactive_metric_is_not_offered(): void
    {
        $this->deactivate(Metric::Pm10);

        $this->get('/')
            ->assertViewHas('metrics', [Metric::Pm25])
            ->assertDontSee('data-metric="pm10"', false);
    }

    public function test_the_first_active_metric_is_the_default_when_pm25_is_inactive(): void
    {
        $this->deactivate(Metric::Pm25);

        $this->get('/')
            ->assertViewHas('metrics', [Metric::Pm10])
            ->assertViewHas('defaultMetric', Metric::Pm10)
            ->assertSee('data-default-metric="pm10"', false);
    }

    public function test_the_page_loads_without_any_active_metric(): void
    {
        $this->deactivate(Metric::Pm25);
        $this->deactivate(Metric::Pm10);

        $this->get('/')
            ->assertOk()
            ->assertViewHas('metrics', [])
            ->assertViewHas('defaultMetric', null)
            ->assertDontSee('data-metric="', false);
    }

    public function test_today_is_the_calendar_day_in_sofia(): void
    {
        $this->travelTo('2026-07-19 21:30:00');

        $this->get('/')
            ->assertViewHas('today', '2026-07-20')
            ->assertSee('data-today="2026-07-20"', false)
            ->assertSee('max="2026-07-20"', false);
    }

    public function test_the_page_points_to_the_ranking_api_and_its_docs(): void
    {
        $this->get('/')
            ->assertSee('data-api-url="'.route('api.v1.ranking').'"', false)
            ->assertSee('href="'.url('/docs/api').'"', false);
    }

    public function test_the_header_links_to_the_metrics_page_next_to_the_api_docs(): void
    {
        $this->get('/')
            ->assertSeeInOrder(['href="'.url('/docs/api').'"', 'API docs', 'href="'.route('metrics').'"', 'Metrics'], false)
            ->assertDontSee('aria-current="page"', false);
    }

    public function test_the_page_loads_no_external_resources_and_no_vite_build(): void
    {
        $response = $this->get('/');
        $html = (string) $response->getContent();
        /** @var string $host */
        $host = parse_url(url('/'), PHP_URL_HOST);

        preg_match_all('/\b(?:src|href)="([^"]*)"/', $html, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $url) {
            $urlHost = parse_url($url, PHP_URL_HOST);
            $this->assertTrue($urlHost === null || $urlHost === $host, "External resource: {$url}");
        }

        $response->assertDontSee('build/manifest', false)
            ->assertDontSee('@vite', false)
            ->assertDontSee('/build/assets/', false);
    }

    public function test_the_referenced_static_files_exist(): void
    {
        $response = $this->get('/');

        foreach (['css/app.css', 'js/theme.js', 'js/ranking.js'] as $file) {
            $this->assertFileExists(public_path($file));
            $response->assertSee(asset($file).'?v=', false);
        }

        $this->assertFileExists(public_path('fonts/dm-sans-latin.woff2'));
        $this->assertFileExists(public_path('fonts/sora-latin.woff2'));
    }

    public function test_rendering_the_page_sends_no_http_request(): void
    {
        $this->get('/')->assertOk();

        Http::assertNothingSent();
    }

    private function deactivate(Metric $metric): void
    {
        QualityMetric::query()->where('slug', $metric->value)->update(['status' => 0]);
    }
}
