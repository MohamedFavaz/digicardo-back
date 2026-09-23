<?php

namespace App\Http\Requests\Analytics;

use App\Enums\AnalyticsEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnalyticsEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public analytics endpoint
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'string', 'max:36'],
            'event_type' => ['required', 'string', Rule::in(AnalyticsEventType::values())],
            'block_id' => ['nullable', 'string', 'max:50'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
            'metadata.destination_host' => ['nullable', 'string', 'max:100'],
            'metadata.provider' => ['nullable', 'string', 'max:50'],
            'metadata.image_index' => ['nullable', 'integer', 'min:0', 'max:50'],
            'metadata.item_index' => ['nullable', 'integer', 'min:0', 'max:50'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'profile_id.required' => 'Profile ID is required.',
            'profile_id.size' => 'Profile ID must be a valid 26-character ULID.',
            'event_type.in' => 'The specified event type is not supported.',
            'block_id.size' => 'Block ID must be a valid 26-character ULID.',
        ];
    }
}
