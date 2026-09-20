<?php

namespace App\Http\Requests\Contact;

use Illuminate\Foundation\Http\FormRequest;

class SubmitContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'message' => ['required', 'string', 'max:2000'],
            'website_hp' => ['nullable', 'string', 'max:100'], // Honeypot field
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Your name is required.',
            'email.required' => 'A valid email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'message.required' => 'A message is required.',
            'message.max' => 'Message must not exceed 2000 characters.',
        ];
    }
}
