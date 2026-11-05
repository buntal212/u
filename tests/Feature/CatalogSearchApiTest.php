<?php

namespace Tests\Feature;

use App\Services\Catalog\CatalogSearchCoordinator;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogSearchApiTest extends TestCase
{
    public function test_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/api/v1/catalog/search', [
            'search_id' => 'search_1',
            'query' => 'nasi',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated server-to-server request.',
            ]);
    }

    public function test_validates_required_fields(): void
    {
        $token = config('services.uih.server_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/catalog/search', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['search_id', 'query']);
    }

    public function test_validates_limit_boundaries(): void
    {
        $token = config('services.uih.server_token');

        // Limit > 50 rejected
        $responseOver = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/catalog/search', [
                'search_id' => 'search_1',
                'query' => 'nasi',
                'limit' => 51,
            ]);

        $responseOver->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        // Limit < 1 rejected
        $responseUnder = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/catalog/search', [
                'search_id' => 'search_1',
                'query' => 'nasi',
                'limit' => 0,
            ]);

        $responseUnder->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_rejects_unknown_fields(): void
    {
        $token = config('services.uih.server_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/catalog/search', [
                'search_id' => 'search_1',
                'query' => 'nasi',
                'unknown_attribute' => 'malicious',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['unknown_attribute']);
    }

    public function test_streams_catalog_search_events_successfully(): void
    {
        $token = config('services.uih.server_token');

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
                'search_id' => 'search_stream_01',
                'query' => 'nasi',
            ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Correlation-ID')));

        $initialBufferLevel = ob_get_level();
        $response->sendContent();
        while (ob_get_level() < $initialBufferLevel) {
            ob_start();
        }

        $this->assertStringContainsString('event: search_init', $capturedContent);
        $this->assertStringContainsString('event: source_result', $capturedContent);
        $this->assertStringContainsString('event: search_done', $capturedContent);
        $this->assertStringContainsString('"search_id":"search_stream_01"', $capturedContent);

        // Verify canonical v1 structure elements in SSE stream
        $this->assertStringContainsString('"source":{"code":"mock_larissama","name":"Larissama F&B"}', $capturedContent);
        $this->assertStringContainsString('"meta":{"total":', $capturedContent);
        $this->assertStringContainsString('"price":{"amount":', $capturedContent);
        $this->assertStringContainsString('"currency":"IDR"', $capturedContent);
        $this->assertStringContainsString('"provider":{', $capturedContent);
    }

    public function test_preserves_valid_incoming_correlation_id_in_response_header(): void
    {
        $token = config('services.uih.server_token');
        $expectedCid = 'b2222222-3333-4444-5555-666666666666';

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Correlation-ID', $expectedCid)
            ->postJson('/api/v1/catalog/search', [
                'search_id' => 'search_stream_cid_01',
                'query' => 'nasi',
            ]);

        $response->assertStatus(200);
        $this->assertSame($expectedCid, $response->headers->get('X-Correlation-ID'));
    }

    public function test_replaces_invalid_correlation_id_with_new_uuid_in_response_header(): void
    {
        $token = config('services.uih.server_token');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Correlation-ID', 'not-a-valid-uuid')
            ->postJson('/api/v1/catalog/search', [
                'search_id' => 'search_stream_cid_02',
                'query' => 'nasi',
            ]);

        $response->assertStatus(200);
        $this->assertNotSame('not-a-valid-uuid', $response->headers->get('X-Correlation-ID'));
        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Correlation-ID')));
    }
}
