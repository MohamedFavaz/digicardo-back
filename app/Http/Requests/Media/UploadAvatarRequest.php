<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
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
                'max:5120', // 5 MB max
                'dimensions:max_width=4000,max_height=4000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'An image file is required.',
            'image.mimes' => 'Avatar must be a JPEG, PNG, or WebP image. SVG and executables are not allowed.',
            'image.max' => 'Avatar file size must not exceed 5 MB.',
            'image.dimensions' => 'Avatar image dimensions must not exceed 4000x4000 pixels.',
        ];
    }
}
