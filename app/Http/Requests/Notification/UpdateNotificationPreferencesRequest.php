<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email_enabled' => ['sometimes', 'boolean'],
            'marketing_email_enabled' => ['sometimes', 'boolean'],
            'contact_email_enabled' => ['sometimes', 'boolean'],
            'subscription_email_enabled' => ['sometimes', 'boolean'],
            'domain_email_enabled' => ['sometimes', 'boolean'],
            'in_app_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
