<?php

namespace App\Http\Requests\Block;

use App\Services\UrlSecurityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBlockRequest extends FormRequest
{
    public const SUPPORTED_TYPES = [
        'link',
        'heading',
        'text',
        'divider',
        'social',
        'image',
        'video',
        'music',
        'map',
        'contact',
        'email',
        'phone',
        'whatsapp',
        'booking',
        'faq',
        'gallery',
        'countdown',
        'cta',
    ];

    public const SUPPORTED_PLATFORMS = [
        'instagram', 'facebook', 'linkedin', 'youtube', 'x', 'tiktok', 'github', 'website'
    ];

    public const URL_REGEX = '/^https?:\/\/[^\s\/$.?#].[^\s]*$/i';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(UrlSecurityService $urlService): array
    {
        $type = $this->input('type');
        $userProfile = $this->user()?->profile;

        $rules = [
            'type' => ['required', 'string', Rule::in(self::SUPPORTED_TYPES)],
            'is_visible' => ['nullable', 'boolean'],
            'config' => ['required', 'array'],
        ];

        if ($type === 'link') {
            $rules['config.title'] = ['required', 'string', 'max:100'];
            $rules['config.url'] = ['required', 'string', 'max:500', 'regex:' . self::URL_REGEX];
            $rules['config.icon'] = ['nullable', 'string', 'max:50'];
            $rules['config.thumbnail_media_id'] = [
                'nullable',
                'string',
                'size:26',
                Rule::exists('profile_media', 'id')->where(fn ($q) => $q->where('profile_id', $userProfile?->id)),
            ];
        } elseif ($type === 'heading') {
            $rules['config.text'] = ['required', 'string', 'max:120'];
            $rules['config.level'] = ['nullable', 'string', Rule::in(['h1', 'h2', 'h3'])];
        } elseif ($type === 'text') {
            $rules['config.content'] = ['required', 'string', 'max:1000'];
            $rules['config.align'] = ['nullable', 'string', Rule::in(['left', 'center', 'right'])];
        } elseif ($type === 'divider') {
            $rules['config.style'] = ['nullable', 'string', Rule::in(['line', 'dots', 'space'])];
        } elseif ($type === 'social') {
            $rules['config.platform'] = ['required', 'string', Rule::in(self::SUPPORTED_PLATFORMS)];
            $rules['config.url'] = ['required', 'string', 'max:500', 'regex:' . self::URL_REGEX];
        } elseif ($type === 'image') {
            $rules['config.media_id'] = [
                'required',
                'string',
                'size:26',
                Rule::exists('profile_media', 'id')->where(fn ($q) => $q->where('profile_id', $userProfile?->id)),
            ];
            $rules['config.alt_text'] = ['nullable', 'string', 'max:255'];
            $rules['config.caption'] = ['nullable', 'string', 'max:255'];
            $rules['config.link_url'] = ['nullable', 'string', 'max:500', 'regex:' . self::URL_REGEX];
            $rules['config.open_in_new_tab'] = ['nullable', 'boolean'];
        } elseif ($type === 'video') {
            $rules['config.provider'] = ['required', 'string', Rule::in(['youtube', 'vimeo'])];
            $rules['config.url'] = [
                'required',
                'string',
                'max:500',
                function ($attribute, $value, $fail) use ($urlService) {
                    if (!$urlService->parseVideoUrl($value)) {
                        $fail('The video URL must be a valid YouTube or Vimeo link.');
                    }
                },
            ];
            $rules['config.title'] = ['nullable', 'string', 'max:120'];
        } elseif ($type === 'music') {
            $rules['config.provider'] = ['required', 'string', Rule::in(['spotify', 'apple_music', 'soundcloud'])];
            $rules['config.url'] = [
                'required',
                'string',
                'max:500',
                function ($attribute, $value, $fail) use ($urlService) {
                    if (!$urlService->parseMusicUrl($value)) {
                        $fail('The music URL must be a valid Spotify, Apple Music, or SoundCloud link.');
                    }
                },
            ];
            $rules['config.title'] = ['nullable', 'string', 'max:120'];
        } elseif ($type === 'map') {
            $rules['config.label'] = ['nullable', 'string', 'max:120'];
            $rules['config.address'] = ['nullable', 'string', 'max:255'];
            $rules['config.latitude'] = ['nullable', 'numeric', 'between:-90,90'];
            $rules['config.longitude'] = ['nullable', 'numeric', 'between:-180,180'];
            $rules['config.map_provider'] = ['nullable', 'string', Rule::in(['google_maps', 'osm'])];
            $rules['config.display_mode'] = ['nullable', 'string', Rule::in(['card', 'embed'])];
            $rules['config.open_in_new_tab'] = ['nullable', 'boolean'];
        } elseif ($type === 'contact') {
            $rules['config.title'] = ['required', 'string', 'max:100'];
            $rules['config.description'] = ['nullable', 'string', 'max:300'];
            $rules['config.name_enabled'] = ['nullable', 'boolean'];
            $rules['config.email_enabled'] = ['nullable', 'boolean'];
            $rules['config.phone_enabled'] = ['nullable', 'boolean'];
            $rules['config.message_enabled'] = ['nullable', 'boolean'];
            $rules['config.button_label'] = ['nullable', 'string', 'max:50'];
        } elseif ($type === 'email') {
            $rules['config.label'] = ['required', 'string', 'max:100'];
            $rules['config.email'] = ['required', 'email', 'max:255'];
            $rules['config.subject'] = ['nullable', 'string', 'max:200'];
            $rules['config.body'] = ['nullable', 'string', 'max:1000'];
        } elseif ($type === 'phone') {
            $rules['config.label'] = ['required', 'string', 'max:100'];
            $rules['config.phone'] = [
                'required',
                'string',
                'max:30',
                function ($attribute, $value, $fail) use ($urlService) {
                    if (!$urlService->normalizePhoneNumber($value)) {
                        $fail('The phone number format is invalid.');
                    }
                },
            ];
        } elseif ($type === 'whatsapp') {
            $rules['config.label'] = ['required', 'string', 'max:100'];
            $rules['config.phone'] = [
                'required',
                'string',
                'max:30',
                function ($attribute, $value, $fail) use ($urlService) {
                    if (!$urlService->normalizePhoneNumber($value)) {
                        $fail('The WhatsApp phone number format is invalid.');
                    }
                },
            ];
            $rules['config.message'] = ['nullable', 'string', 'max:500'];
        } elseif ($type === 'booking') {
            $rules['config.provider'] = ['required', 'string', Rule::in(['calendly', 'cal'])];
            $rules['config.url'] = [
                'required',
                'string',
                'max:500',
                function ($attribute, $value, $fail) use ($urlService) {
                    if (!$urlService->validateBookingUrl($value)) {
                        $fail('Booking URL must be an allowlisted Calendly or Cal.com link.');
                    }
                },
            ];
            $rules['config.title'] = ['nullable', 'string', 'max:100'];
            $rules['config.display_mode'] = ['nullable', 'string', Rule::in(['button', 'inline_embed'])];
        } elseif ($type === 'faq') {
            $rules['config.title'] = ['nullable', 'string', 'max:120'];
            $rules['config.items'] = ['required', 'array', 'min:1', 'max:20'];
            $rules['config.items.*.id'] = ['required', 'string', 'max:36'];
            $rules['config.items.*.question'] = ['required', 'string', 'max:200'];
            $rules['config.items.*.answer'] = ['required', 'string', 'max:1000'];
        } elseif ($type === 'gallery') {
            $rules['config.layout'] = ['nullable', 'string', Rule::in(['grid', 'carousel', 'masonry'])];
            $rules['config.media_ids'] = [
                'required',
                'array',
                'min:1',
                'max:20',
                function ($attribute, $value, $fail) {
                    if (is_array($value) && count($value) !== count(array_unique($value))) {
                        $fail('Duplicate media items are not allowed in the gallery.');
                    }
                },
            ];
            $rules['config.media_ids.*'] = [
                'required',
                'string',
                'size:26',
                Rule::exists('profile_media', 'id')->where(fn ($q) => $q->where('profile_id', $userProfile?->id)),
            ];
        } elseif ($type === 'countdown') {
            $rules['config.title'] = ['required', 'string', 'max:120'];
            $rules['config.target_date'] = ['required', 'date'];
            $rules['config.expired_message'] = ['nullable', 'string', 'max:200'];
        } elseif ($type === 'cta') {
            $rules['config.title'] = ['required', 'string', 'max:120'];
            $rules['config.description'] = ['nullable', 'string', 'max:300'];
            $rules['config.button_label'] = ['required', 'string', 'max:60'];
            $rules['config.url'] = ['required', 'string', 'max:500', 'regex:' . self::URL_REGEX];
            $rules['config.style'] = ['nullable', 'string', Rule::in(['primary', 'secondary', 'outline', 'gradient'])];
            $rules['config.size'] = ['nullable', 'string', Rule::in(['small', 'medium', 'large'])];
            $rules['config.open_in_new_tab'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'The selected block type is invalid.',
            'config.url.regex' => 'The URL must be a valid HTTP or HTTPS address. Javascript and other URI schemes are prohibited.',
            'config.link_url.regex' => 'The destination link URL must be a valid HTTP or HTTPS address.',
            'config.platform.in' => 'The selected social platform is invalid.',
            'config.media_id.required' => 'A valid media attachment is required for image blocks.',
            'config.media_id.exists' => 'The selected media item does not exist or does not belong to your profile.',
            'config.media_ids.*.exists' => 'One or more gallery images do not exist or do not belong to your profile.',
            'config.media_ids.distinct' => 'Duplicate media items are not allowed in the gallery.',
            'config.items.min' => 'At least one FAQ item is required.',
            'config.target_date.date' => 'The countdown target date must be a valid date/time.',
        ];
    }
}
