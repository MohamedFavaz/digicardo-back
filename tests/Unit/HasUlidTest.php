<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class HasUlidTest extends TestCase
{
    public function test_ulid_format_matches_standard_specification(): void
    {
        $ulid = (string) Str::ulid();

        // Standard Crockford Base32 26-character string
        $this->assertEquals(26, strlen($ulid));
        $this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $ulid);
    }

    public function test_user_model_has_non_incrementing_string_key(): void
    {
        $user = new User();

        $this->assertFalse($user->getIncrementing());
        $this->assertEquals('string', $user->getKeyType());
        $this->assertEquals('id', $user->getKeyName());
    }
}
