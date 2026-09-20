<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'in:pro,business,PRO,BUSINESS'],
            'interval' => ['required', 'string', 'in:monthly,yearly,MONTHLY,YEARLY'],
            'success_url' => ['nullable', 'string', 'url'],
            'cancel_url' => ['nullable', 'string', 'url'],
        ];
    }
}
