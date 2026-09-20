<?php

namespace App\Http\Requests\Profile;

use App\Services\UsernameService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation (normalize username).
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
        $profileId = $this->user()?->profile?->id;

        return [
            'username' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:' . UsernameService::USERNAME_REGEX,
                Rule::notIn(UsernameService::RESERVED_USERNAMES),
                Rule::unique('profiles', 'username')->ignore($profileId),
            ],
            'display_name' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar_url' => ['nullable', 'string', 'url', 'max:500'],
            'template_id' => ['nullable', 'string', 'max:50'],
            'is_public' => ['nullable', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:100'],
            'seo_description' => ['nullable', 'string', 'max:300'],
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
            'username.regex' => 'Username must be 3-30 characters, starting with a letter or number, using only lowercase letters, numbers, hyphens, and underscores.',
            'username.not_in' => 'This username is reserved and cannot be claimed.',
            'username.unique' => 'This username is already taken. Please choose another.',
        ];
    }
}
