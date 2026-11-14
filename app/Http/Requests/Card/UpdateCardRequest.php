<?php

namespace App\Http\Requests\Card;

use App\Enums\CardCategory;
use App\Enums\CardStatus;
use App\Services\UsernameService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateCardRequest extends FormRequest
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
        $cardId = $this->route('card')?->id ?? $this->route('card');

        return [
            'card_title' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', new Enum(CardCategory::class)],
            'status' => ['nullable', new Enum(CardStatus::class)],
            'display_name' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:500'],
            'username' => [
                'nullable',
                'string',
                'min:3',
                'max:30',
                'regex:' . UsernameService::USERNAME_REGEX,
                Rule::notIn(UsernameService::RESERVED_USERNAMES),
                Rule::unique('profiles', 'username')->ignore($cardId),
            ],
            'avatar_url' => ['nullable', 'string', 'max:500'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'template_id' => ['nullable', 'string', 'max:50'],
            'theme_tokens' => ['nullable', 'array'],
            'is_public' => ['nullable', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:100'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            'version' => ['required', 'integer'],
        ];
    }
}
