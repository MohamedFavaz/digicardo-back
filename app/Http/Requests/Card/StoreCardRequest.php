<?php

namespace App\Http\Requests\Card;

use App\Enums\CardCategory;
use App\Services\UsernameService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreCardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare input for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('username') && is_string($this->username)) {
            $this->merge([
                'username' => UsernameService::normalize($this->username),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', new Enum(CardCategory::class)],
            'template_id' => ['required', 'string', 'max:50'],
            'card_title' => ['nullable', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:500'],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:30',
                'regex:' . UsernameService::USERNAME_REGEX,
                Rule::notIn(UsernameService::RESERVED_USERNAMES),
                'unique:profiles,username',
            ],
            'avatar_url' => ['nullable', 'string', 'max:500'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'theme_tokens' => ['nullable', 'array'],
        ];
    }
}
