<?php

namespace App\Http\Requests\Profile;

use App\Models\ProfileMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProfileSeoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
            'seo_keywords' => ['nullable', 'array', 'max:10'],
            'seo_keywords.*' => ['string', 'max:50'],
            'og_title' => ['nullable', 'string', 'max:95'],
            'og_description' => ['nullable', 'string', 'max:200'],
            'og_image_media_id' => ['nullable', 'string', 'size:26'],
            'indexable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Prepare the data for validation and sanitize plain text inputs.
     */
    protected function prepareForValidation(): void
    {
        $sanitized = [];

        if ($this->has('seo_title')) {
            $sanitized['seo_title'] = $this->seo_title ? strip_tags(trim((string) $this->seo_title)) : null;
        }

        if ($this->has('seo_description')) {
            $sanitized['seo_description'] = $this->seo_description ? strip_tags(trim((string) $this->seo_description)) : null;
        }

        if ($this->has('og_title')) {
            $sanitized['og_title'] = $this->og_title ? strip_tags(trim((string) $this->og_title)) : null;
        }

        if ($this->has('og_description')) {
            $sanitized['og_description'] = $this->og_description ? strip_tags(trim((string) $this->og_description)) : null;
        }

        if ($this->has('seo_keywords') && is_array($this->seo_keywords)) {
            $sanitized['seo_keywords'] = array_values(array_filter(
                array_map(fn($kw) => strip_tags(trim((string) $kw)), $this->seo_keywords),
                fn($kw) => $kw !== ''
            ));
        }

        if (!empty($sanitized)) {
            $this->merge($sanitized);
        }
    }

    /**
     * Additional validation to ensure og_image_media_id is owned by the user.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mediaId = $this->input('og_image_media_id');
            if ($mediaId) {
                $user = $this->user();
                $exists = ProfileMedia::where('id', $mediaId)
                    ->where('user_id', $user->id)
                    ->exists();

                if (!$exists) {
                    $validator->errors()->add(
                        'og_image_media_id',
                        'The selected Open Graph image media does not belong to your account or does not exist.'
                    );
                }
            }
        });
    }
}
