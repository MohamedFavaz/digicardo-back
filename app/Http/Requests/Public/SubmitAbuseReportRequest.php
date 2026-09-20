<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAbuseReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:spam,phishing,impersonation,malicious_content,copyright,harassment,inappropriate_content,other'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'block_id' => ['nullable', 'string', 'size:26'],
            'reporter_email' => ['nullable', 'email', 'max:255'],
            'hp_field' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:100'],
        ];
    }
}
