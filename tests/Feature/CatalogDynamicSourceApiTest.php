<?php

namespace Tests\Feature;

use App\Services\Catalog\CatalogSearchCoordinator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogDynamicSourceApiTest extends TestCase
{
    public function test_streams_results_from_dynamic_asu_sources_configured_in_environment(): void
    {
        $token = config('services.uih.server_token');

        $asuSources = json_encode([
            'larissama' => [
                'name' => 'Larissama F&B',
                'url' => 'http://larissama-api:8000',
                'token' => 'larissama-test-token',
            ],
            'laundry' => [
                'name' => 'Laundry Express',
                'url' => 'http://laundry-api:8000',
                'token' => 'laundry-test-token',
            ],
        ]);

        config([
            'catalog.mode' => 'dynamic',
            'catalog.sources' => $asuSources,
        ]);

        // Rebind or refresh coordinator in container for this test
        $this->app->forgetInstance(CatalogSearchCoordinator::class);

        Http::fake([
            'http://larissama-api:8000/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'lrs-item-1',
                        'name' => 'Nasi Bakar',
                        'price' => ['amount' => 20000, 'currency' => 'IDR'],
                    ],
                ],
            ], 200),
            'http://laundry-api:8000/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'lnd-item-1',
                        'name' => 'Cuci Kering',
                        'price' => ['amount' => 15000, 'currency' => 'IDR'],
                    ],
                ],
            ], 200),
        ]);

        $capturedContent = '';
        $realCoordinator = app(CatalogSearchCoordinator::class);
        $this->app->bind(CatalogSearchCoordinator::class, function () use ($realCoordinator, &$capturedContent) {
            return new class($realCoordinator, $capturedContent) extends CatalogSearchCoordinator
            {
                public function __construct(
                    private CatalogSearchCoordinator $real,
                    private string &$captured
                ) {}

                public function executeStream(string $searchId, string $query, int $limit, callable $eventEmitter): void
                {
                    ob_start(function (string $chunk): string {
                        $this->captured .= $chunk;

                        return '';
                    });

                    try {
                        $this->real->executeStream($searchId, $query, $limit, $eventEmitter);
                    } finally {
                        if (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                    }
                }
            };
        });

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/catalog/search', [
                'search_id' => 'dynamic_test_stream_01',
                'query' => 'nasi',
            ]);

        $response->assertStatus(200);

        $initialBufferLevel = ob_get_level();
        $response->sendContent();
        while (ob_get_level() < $initialBufferLevel) {
            ob_start();
        }

        $this->assertStringContainsString('event: search_init', $capturedContent);
        $this->assertStringContainsString('"targets":["larissama","laundry"]', $capturedContent);

        $this->assertStringContainsString('event: source_result', $capturedContent);
        $this->assertStringContainsString('"code":"larissama"', $capturedContent);
        $this->assertStringContainsString('"code":"laundry"', $capturedContent);
        $this->assertStringContainsString('Nasi Bakar', $capturedContent);
        $this->assertStringContainsString('Cuci Kering', $capturedContent);

        $this->assertStringContainsString('event: search_done', $capturedContent);
        $this->assertStringContainsString('"total_items":2', $capturedContent);
        $this->assertStringContainsString('"larissama":"completed"', $capturedContent);
        $this->assertStringContainsString('"laundry":"completed"', $capturedContent);
    }
}
