<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class ProductionCheckTest extends TestCase
{
    public function test_production_check_command_runs_successfully(): void
    {
        $this->artisan('Digicardo:production-check')
            ->expectsOutputToContain('Digicardo — Production Readiness Check')
            ->expectsOutputToContain('APP_KEY Configuration')
            ->expectsOutputToContain('Database Connectivity')
            ->expectsOutputToContain('Cache Subsystem')
            ->assertExitCode(0);
    }

    public function test_production_check_command_supports_json_flag(): void
    {
        $this->artisan('Digicardo:production-check', ['--json' => true])
            ->assertExitCode(0);
    }
}
