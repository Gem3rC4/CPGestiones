<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_is_public_and_does_not_expose_secrets(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['ok' => true, 'name' => 'CP Gestiones', 'version' => '1.2.0']);
    }

    public function test_root_identifies_the_api_in_json(): void
    {
        $this->getJson('/')->assertOk()->assertJsonPath('api', '/api/v1')->assertHeader('Content-Type', 'application/json');
    }
}
