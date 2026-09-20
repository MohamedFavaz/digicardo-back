<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class UploadCoverRequest extends FormRequest
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
            'image' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:8192', // 8 MB max
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'An image file is required.',
            'image.mimes' => 'Cover image must be a JPEG, PNG, or WebP image. SVG and executables are not allowed.',
            'image.max' => 'Cover image file size must not exceed 8 MB.',
            'image.dimensions' => 'Cover image dimensions must not exceed 6000x6000 pixels.',
        ];
    }
}
