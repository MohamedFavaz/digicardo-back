<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class UploadDocumentRequest extends FormRequest
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
            'document' => [
                'required',
                'file',
                'mimes:pdf',
                'max:20480', // 20 MB max
            ],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => 'A document file is required.',
            'document.file' => 'The uploaded document is invalid.',
            'document.mimes' => 'Only PDF documents are allowed (.pdf).',
            'document.max' => 'Document file size must not exceed 20 MB.',
            'title.max' => 'Document title must not exceed 255 characters.',
        ];
    }
}
