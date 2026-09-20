<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\ErrorTrackingProviderInterface;
use App\Services\Observability\NullErrorTrackingProvider;
use Exception;
use Tests\TestCase;

class ErrorTrackingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        NullErrorTrackingProvider::clear();
    }

    public function test_error_tracking_provider_is_bound(): void
    {
        $provider = app(ErrorTrackingProviderInterface::class);
        $this->assertInstanceOf(ErrorTrackingProviderInterface::class, $provider);
    }

    public function test_null_provider_captures_and_sanitizes_exceptions(): void
    {
        $provider = new NullErrorTrackingProvider();

        $eventId = $provider->captureException(new Exception('Payment failed'), [
            'user_id' => '01HZMD5R4GKJA7TFP4QYX8VBJN',
            'password' => 'secret-password-should-be-redacted',
            'token' => 'secret-token-redacted',
            'plan' => 'pro',
        ]);

        $this->assertNotNull($eventId);
        $events = NullErrorTrackingProvider::getCapturedEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertEquals('Payment failed', $event['message']);
        $this->assertEquals('Exception', $event['exception_class']);
        $this->assertEquals('01HZMD5R4GKJA7TFP4QYX8VBJN', $event['context']['user_id']);
        $this->assertEquals('[REDACTED]', $event['context']['password']);
        $this->assertEquals('[REDACTED]', $event['context']['token']);
        $this->assertEquals('pro', $event['context']['plan']);
    }

    public function test_null_provider_captures_messages(): void
    {
        $provider = new NullErrorTrackingProvider();

        $eventId = $provider->captureMessage('Domain provisioning started', 'info', [
            'domain' => 'mybrand.com',
            'api_key' => 'secret-api-key',
        ]);

        $this->assertNotNull($eventId);
        $events = NullErrorTrackingProvider::getCapturedEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertEquals('Domain provisioning started', $event['message']);
        $this->assertEquals('info', $event['level']);
        $this->assertEquals('mybrand.com', $event['context']['domain']);
        $this->assertEquals('[REDACTED]', $event['context']['api_key']);
    }
}
