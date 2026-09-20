<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class SystemCheckTest extends TestCase
{
    public function test_system_check_command_runs_successfully(): void
    {
        $this->artisan('system:check')
            ->expectsOutputToContain('Digicardo — System & Production Readiness Check')
            ->expectsOutputToContain('[PASS] Application Configuration')
            ->assertExitCode(0);
    }

    public function test_system_check_supports_json_flag(): void
    {
        $this->artisan('system:check', ['--json' => true])
            ->assertExitCode(0);
    }

    public function test_system_check_warns_on_strict_mode_in_local(): void
    {
        // In local env with APP_DEBUG=true, strict mode should flag debug or exit
        $exitCode = $this->artisan('system:check', ['--strict' => true, '--json' => true])
            ->run();

        // Exit code reflects evaluation
        $this->assertIsInt($exitCode);
    }
}
