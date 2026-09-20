<?php

namespace App\Http\Requests\Block;

use Illuminate\Foundation\Http\FormRequest;

class ReorderBlocksRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ordered_ids.required' => 'An array of ordered block IDs is required.',
            'ordered_ids.*.distinct' => 'Duplicate block IDs are not permitted in the reorder payload.',
            'ordered_ids.*.size' => 'Each block ID must be a valid 26-character ULID.',
            'version.required' => 'The current profile version is required for optimistic concurrency.',
        ];
    }
}
