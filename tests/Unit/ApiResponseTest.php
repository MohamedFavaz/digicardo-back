<?php

namespace Tests\Unit;

use App\Traits\ApiResponse;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    private object $classUsingTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classUsingTrait = new class {
            use ApiResponse;

            public function callSuccess(mixed $data = null, array $meta = [], int $status = 200)
            {
                return $this->successResponse($data, $meta, $status);
            }

            public function callError(string $code, string $message, mixed $details = null, int $status = 400)
            {
                return $this->errorResponse($code, $message, $details, $status);
            }
        };
    }

    public function test_success_response_structure(): void
    {
        $response = $this->classUsingTrait->callSuccess(['user_id' => '01HZMD5R4GKJA7TFP4QYX8VBJN'], ['version' => '1.0']);
        $payload = json_decode($response->getContent(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertEquals(['user_id' => '01HZMD5R4GKJA7TFP4QYX8VBJN'], $payload['data']);
        $this->assertEquals(['version' => '1.0'], $payload['meta']);
    }

    public function test_error_response_structure(): void
    {
        $response = $this->classUsingTrait->callError('NOT_FOUND', 'Resource not found.', null, 404);
        $payload = json_decode($response->getContent(), true);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertEquals('NOT_FOUND', $payload['error']['code']);
        $this->assertEquals('Resource not found.', $payload['error']['message']);
        $this->assertArrayNotHasKey('details', $payload['error']);
    }

    public function test_error_response_with_details(): void
    {
        $response = $this->classUsingTrait->callError('VALIDATION_ERROR', 'Invalid data.', ['email' => ['Required.']], 422);
        $payload = json_decode($response->getContent(), true);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertEquals('VALIDATION_ERROR', $payload['error']['code']);
        $this->assertEquals(['email' => ['Required.']], $payload['error']['details']);
    }
}
