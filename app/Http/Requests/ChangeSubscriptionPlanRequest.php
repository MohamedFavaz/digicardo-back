<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangeSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'in:pro,business,PRO,BUSINESS'],
            'interval' => ['nullable', 'string', 'in:monthly,yearly,MONTHLY,YEARLY'],
        ];
    }
}
