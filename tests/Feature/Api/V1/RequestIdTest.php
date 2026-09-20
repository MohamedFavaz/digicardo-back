<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_api_generates_request_id_if_missing(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);
        $this->assertTrue($response->headers->has('X-Request-ID'));
        $requestId = $response->headers->get('X-Request-ID');
        $this->assertStringStartsWith('req_', $requestId);
    }

    public function test_api_propagates_valid_incoming_request_id(): void
    {
        $customId = 'req_custom_trace_123456';

        $response = $this->withHeaders([
            'X-Request-ID' => $customId,
        ])->getJson('/api/v1/health');

        $response->assertStatus(200);
        $this->assertEquals($customId, $response->headers->get('X-Request-ID'));
    }

    public function test_api_sanitizes_malicious_or_excessively_long_request_id(): void
    {
        $maliciousId = str_repeat('A', 100) . '<script>alert(1)</script>';

        $response = $this->withHeaders([
            'X-Request-ID' => $maliciousId,
        ])->getJson('/api/v1/health');

        $response->assertStatus(200);
        $sanitizedId = $response->headers->get('X-Request-ID');
        $this->assertNotEquals($maliciousId, $sanitizedId);
        $this->assertStringStartsWith('req_', $sanitizedId);
    }
}
