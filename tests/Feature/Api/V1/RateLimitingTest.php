<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    public function test_api_rate_limiter_is_configured(): void
    {
        $limiter = RateLimiter::limiter('api');
        $this->assertNotNull($limiter, 'The "api" rate limiter must be registered.');
    }

    public function test_auth_rate_limiter_is_configured(): void
    {
        $limiter = RateLimiter::limiter('auth');
        $this->assertNotNull($limiter, 'The "auth" rate limiter must be registered.');
    }
}
