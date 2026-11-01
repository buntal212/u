<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    public function test_unknown_api_routes_return_json_404_responses(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
