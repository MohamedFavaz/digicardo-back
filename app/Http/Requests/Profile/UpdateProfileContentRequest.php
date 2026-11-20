<?php

namespace App\Http\Requests\Profile;

use App\Rules\OwnedMediaUrl;
use App\Rules\SafeUrl;
use App\Services\CardContentSchema;
use App\Services\ProfileContentService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /profile/content — `{version, sections: {identity: {...}, socials: [...]}}`.
 * Each part sent replaces that part; parts not sent are untouched.
 * Mirror: frontend/src/lib/zod/content.ts (AGENTS.md rule 12).
 */
class UpdateProfileContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $ifsc = $this->input('sections.payments.bank.ifsc');
        if (is_string($ifsc)) {
            $sections = $this->input('sections');
            $sections['payments']['bank']['ifsc'] = strtoupper(trim($ifsc));
            $this->merge(['sections' => $sections]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $profile = $this->user()?->profile;
        $stored = $profile ? CardContentSchema::imageUrls(app(ProfileContentService::class)->contentFor($profile)) : [];
        $image = ['nullable', 'string', 'max:' . SafeUrl::MAX_LENGTH, new OwnedMediaUrl($profile, $stored)];
        $url = ['nullable', 'string', new SafeUrl()];
        $phone = ['nullable', 'string', 'max:24', $this->phoneRule()];
        $itemId = ['required', 'string', 'regex:' . CardContentSchema::ITEM_ID_REGEX];
        $limit = CardContentSchema::LIMITS;

        return [
            'version' => ['required', 'integer', 'min:1'],
            'sections' => ['required', 'array:' . implode(',', CardContentSchema::PARTS), 'min:1'],

            'sections.identity' => ['sometimes', 'array'],
            'sections.identity.name' => ['required_with:sections.identity', 'string', 'max:100'],
            'sections.identity.role' => ['nullable', 'string', 'max:100'],
            'sections.identity.company' => ['nullable', 'string', 'max:100'],
            'sections.identity.bio' => ['nullable', 'string', 'max:500'],
            'sections.identity.avatar_url' => $image,
            'sections.identity.logo_url' => $image,
            'sections.identity.cover_images' => ['nullable', 'array', 'max:' . $limit['cover_images']],
            'sections.identity.cover_images.*' => $image,
            'sections.identity.category_label' => ['nullable', 'string', 'max:60'],
            'sections.identity.hours' => ['nullable', 'string', 'max:80'],
            'sections.identity.badges' => ['nullable', 'array', 'max:' . $limit['badges']],
            'sections.identity.badges.*' => ['nullable', 'string', 'max:40'],
            'sections.identity.verified_badge' => ['sometimes', 'boolean'],

            'sections.contact' => ['sometimes', 'array'],
            'sections.contact.phone' => $phone,
            'sections.contact.whatsapp' => $phone,
            'sections.contact.email' => ['nullable', 'string', 'max:254', 'email:rfc'],
            'sections.contact.website' => $url,
            'sections.contact.address' => ['nullable', 'string', 'max:300'],
            'sections.contact.map_url' => $url,

            'sections.socials' => ['sometimes', 'array', 'max:' . $limit['socials']],
            'sections.socials.*.id' => $itemId,
            'sections.socials.*.platform' => ['required', 'string', Rule::in(CardContentSchema::SOCIAL_PLATFORMS)],
            'sections.socials.*.url' => ['required', 'string', new SafeUrl()],

            'sections.catalog' => ['sometimes', 'array'],
            'sections.catalog.services_label' => ['nullable', 'string', 'max:40'],
            'sections.catalog.products_label' => ['nullable', 'string', 'max:40'],
            'sections.catalog.enquiry_message' => ['nullable', 'string', 'max:300'],
            'sections.catalog.services' => ['nullable', 'array', 'max:' . $limit['services']],
            'sections.catalog.services.*.id' => $itemId,
            'sections.catalog.services.*.name' => ['required', 'string', 'max:100'],
            'sections.catalog.services.*.description' => ['nullable', 'string', 'max:300'],
            'sections.catalog.services.*.price' => ['nullable', 'string', 'max:40'],
            'sections.catalog.services.*.duration' => ['nullable', 'string', 'max:40'],
            'sections.catalog.services.*.image_url' => $image,
            'sections.catalog.products' => ['nullable', 'array', 'max:' . $limit['products']],
            'sections.catalog.products.*.id' => $itemId,
            'sections.catalog.products.*.name' => ['required', 'string', 'max:100'],
            'sections.catalog.products.*.description' => ['nullable', 'string', 'max:300'],
            'sections.catalog.products.*.price' => ['nullable', 'string', 'max:40'],
            'sections.catalog.products.*.image_url' => $image,
            'sections.catalog.products.*.buy_url' => $url,

            'sections.payments' => ['sometimes', 'array'],
            'sections.payments.upi_id' => ['nullable', 'string', 'regex:' . CardContentSchema::UPI_REGEX],
            'sections.payments.upi_name' => ['nullable', 'string', 'max:100'],
            'sections.payments.payment_phone' => $phone,
            'sections.payments.bank' => ['nullable', 'array'],
            'sections.payments.bank.holder' => ['nullable', 'string', 'max:100'],
            'sections.payments.bank.bank_name' => ['nullable', 'string', 'max:100'],
            'sections.payments.bank.account_number' => ['nullable', 'string', 'regex:/^[0-9A-Za-z]{4,34}$/'],
            'sections.payments.bank.ifsc' => ['nullable', 'string', 'regex:' . CardContentSchema::IFSC_REGEX],

            'sections.gallery' => ['sometimes', 'array'],
            'sections.gallery.title' => ['nullable', 'string', 'max:60'],
            'sections.gallery.images' => ['nullable', 'array', 'max:' . $limit['gallery']],
            'sections.gallery.images.*' => $image,

            'sections.links' => ['sometimes', 'array', 'max:' . $limit['links']],
            'sections.links.*.id' => $itemId,
            'sections.links.*.title' => ['required', 'string', 'max:100'],
            'sections.links.*.description' => ['nullable', 'string', 'max:200'],
            'sections.links.*.url' => ['required', 'string', new SafeUrl()],
            'sections.links.*.icon' => ['nullable', 'string', 'regex:/^[a-z_]{1,30}$/'],
            'sections.links.*.featured' => ['sometimes', 'boolean'],

            'sections.documents' => ['sometimes', 'array', 'max:' . $limit['documents']],
            'sections.documents.*.id' => $itemId,
            'sections.documents.*.title' => ['required', 'string', 'max:100'],
            'sections.documents.*.description' => ['nullable', 'string', 'max:200'],
            'sections.documents.*.url' => ['required', 'string', new SafeUrl()],
            'sections.documents.*.file_name' => ['nullable', 'string', 'max:200'],
            'sections.documents.*.size_label' => ['nullable', 'string', 'max:20'],

            'sections.actions' => ['sometimes', 'array'],
            'sections.actions.save_contact' => ['sometimes', 'boolean'],
            'sections.actions.share' => ['sometimes', 'boolean'],
            'sections.actions.qr' => ['sometimes', 'boolean'],
            'sections.actions.review_url' => $url,
            'sections.actions.booking_message' => ['nullable', 'string', 'max:300'],

            'sections.layout' => ['sometimes', 'array', 'max:' . count(CardContentSchema::SECTION_KEYS)],
            'sections.layout.*.key' => ['required', 'string', 'distinct', Rule::in(CardContentSchema::SECTION_KEYS)],
            'sections.layout.*.visible' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sections.array' => 'Unknown card section. Allowed: ' . implode(', ', CardContentSchema::PARTS) . '.',
            'sections.payments.upi_id.regex' => 'Enter a UPI ID like name@bank.',
            'sections.payments.bank.ifsc.regex' => 'Enter an 11-character IFSC code like HDFC0001234.',
            'sections.payments.bank.account_number.regex' => 'Use only letters and numbers (4–34 characters).',
            'sections.contact.email.email' => 'Enter an email address like name@example.com.',
            'sections.links.*.icon.regex' => 'Unknown icon.',
        ];
    }

    /**
     * Phone numbers: digits with optional +, spaces, dashes and brackets; 7–15 digits.
     */
    private function phoneRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            $digits = is_string($value) ? (preg_replace('/\D/', '', $value) ?? '') : '';
            if (!is_string($value) || preg_match('/^\+?[0-9 ()\-]{7,24}$/', $value) !== 1 || strlen($digits) < 7 || strlen($digits) > 15) {
                $fail('Enter a phone number with country code, like +91 98765 43210.');
            }
        };
    }
}
