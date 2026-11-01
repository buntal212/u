<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_the_versioned_json_contract(): void
    {
        $this->get('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'application' => 'udumbara-internal-hub',
                'version' => 'v1',
            ]);
    }
}
