<?php

namespace App\Rules;

use App\Models\Profile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An image on the card must be one the owner uploaded (profile_media of this profile).
 * URLs already stored on the card are also accepted, so saving a section never fails
 * because of an older image that predates this rule.
 */
class OwnedMediaUrl implements ValidationRule
{
    /** @var array<string, true>|null */
    private ?array $allowed = null;

    /**
     * @param  list<string>  $alreadyStored  Image URLs currently on the card.
     */
    public function __construct(
        private readonly ?Profile $profile,
        private readonly array $alreadyStored = []
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value) || !isset($this->allowedUrls()[trim($value)])) {
            $fail('Upload this image to your card first, then pick it here.');
        }
    }

    /**
     * @return array<string, true>
     */
    private function allowedUrls(): array
    {
        if ($this->allowed !== null) {
            return $this->allowed;
        }

        $allowed = [];
        foreach ($this->alreadyStored as $url) {
            $allowed[trim($url)] = true;
        }

        if ($this->profile) {
            foreach ([$this->profile->avatar_url, $this->profile->cover_url] as $url) {
                if (is_string($url) && $url !== '') {
                    $allowed[trim($url)] = true;
                }
            }

            foreach ($this->profile->media()->get() as $media) {
                $allowed[$media->url] = true;
            }
        }

        return $this->allowed = $allowed;
    }
}
