<?php

namespace Tests\Feature;

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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/catalog/search', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['search_id', 'query']);
    }

    public function test_rejects_unknown_fields(): void
    {
        $token = config('services.uih.server_token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
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

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/catalog/search', [
                'search_id' => 'search_stream_01',
                'query' => 'nasi',
            ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));

        // Capture streamed content
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertStringContainsString('event: search_init', $content);
        $this->assertStringContainsString('event: source_result', $content);
        $this->assertStringContainsString('event: search_done', $content);
        $this->assertStringContainsString('"search_id":"search_stream_01"', $content);
    }
}
