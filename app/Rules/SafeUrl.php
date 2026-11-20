<?php

namespace App\Rules;

use App\Services\UrlSecurityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An absolute http(s) URL with a host. Everything else (javascript:, data:, mailto:,
 * relative paths, bare domains) is rejected; tel:/mailto:/wa.me links are built by the
 * app from phone numbers and emails, never stored (ADR-030).
 */
class SafeUrl implements ValidationRule
{
    public const MAX_LENGTH = 2048;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) || mb_strlen($value) > self::MAX_LENGTH || !app(UrlSecurityService::class)->isValidHttpUrl($value)) {
            $fail('Enter a full web address that starts with https://');
        }
    }
}
