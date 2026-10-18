<?php

namespace Tests\Feature\Web;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetricsPageTest extends TestCase
{
    public function test_the_page_renders_the_metrics_view(): void
    {
        $this->get('/metrics')
            ->assertOk()
            ->assertViewIs('metrics')
            ->assertSee('<title>Metrics · Clean Air Finder</title>', false)
            ->assertSee('id="health"', false)
            ->assertSee('id="refresh"', false);
    }

    public function test_the_page_points_to_the_health_and_metrics_endpoints(): void
    {
        $this->get('/metrics')
            ->assertSee('data-health-url="'.route('health').'"', false)
            ->assertSee('data-metrics-url="'.route('api.v1.metrics').'"', false);
    }

    public function test_the_header_links_to_the_api_docs_and_marks_metrics_as_current(): void
    {
        $this->get('/metrics')
            ->assertSee('href="'.url('/docs/api').'"', false)
            ->assertSee('href="'.route('metrics').'" aria-current="page"', false);
    }

    public function test_the_page_loads_no_external_resources(): void
    {
        $html = (string) $this->get('/metrics')->getContent();
        /** @var string $host */
        $host = parse_url(url('/'), PHP_URL_HOST);

        preg_match_all('/\b(?:src|href)="([^"]*)"/', $html, $matches);

        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $url) {
            $urlHost = parse_url($url, PHP_URL_HOST);
            $this->assertTrue($urlHost === null || $urlHost === $host, "External resource: {$url}");
        }
    }

    public function test_the_referenced_static_files_exist(): void
    {
        $response = $this->get('/metrics');

        foreach (['css/app.css', 'js/theme.js', 'js/metrics.js'] as $file) {
            $this->assertFileExists(public_path($file));
            $response->assertSee(asset($file).'?v=', false);
        }
    }

    public function test_rendering_the_page_sends_no_http_request(): void
    {
        $this->get('/metrics')->assertOk();

        Http::assertNothingSent();
    }
}
