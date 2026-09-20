<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class ApiResponseFormatTest extends TestCase
{
    public function test_not_found_route_returns_standardized_error_format(): void
    {
        $response = $this->getJson('/api/v1/nonexistent-resource');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Resource not found.',
                ],
            ]);
    }

    public function test_api_v1_versioning_is_enforced(): void
    {
        $v1Response = $this->getJson('/api/v1/health');
        $v1Response->assertStatus(200);

        $unversionedResponse = $this->getJson('/api/health');
        $unversionedResponse->assertStatus(404);
    }
}
