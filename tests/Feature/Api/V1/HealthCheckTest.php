<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_endpoint_returns_success(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'status',
                    'timestamp',
                    'version',
                    'checks',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.version', 'v1');
    }

    public function test_health_check_does_not_expose_sensitive_system_information(): void
    {
        $response = $this->getJson('/api/v1/health');

        $content = $response->getContent();

        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('mysql', strtolower($content));
        $this->assertStringNotContainsString('app_key', strtolower($content));
        $this->assertStringNotContainsString('secret', strtolower($content));
        $this->assertStringNotContainsString('token', strtolower($content));
    }
}
