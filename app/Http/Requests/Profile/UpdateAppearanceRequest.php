<?php

namespace App\Http\Requests\Profile;

use App\Services\TemplateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppearanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template_id' => [
                'required',
                'string',
                Rule::in(array_keys(TemplateService::APPROVED_TEMPLATES)),
            ],
            'version' => ['required', 'integer', 'min:1'],
            'theme_tokens' => ['nullable', 'array'],
            'theme_tokens.color_background' => ['required_with:theme_tokens', 'string', 'regex:' . TemplateService::HEX_COLOR_REGEX],
            'theme_tokens.color_surface' => ['required_with:theme_tokens', 'string', 'regex:' . TemplateService::HEX_COLOR_REGEX],
            'theme_tokens.color_text_primary' => ['required_with:theme_tokens', 'string', 'regex:' . TemplateService::HEX_COLOR_REGEX],
            'theme_tokens.color_text_secondary' => ['required_with:theme_tokens', 'string', 'regex:' . TemplateService::HEX_COLOR_REGEX],
            'theme_tokens.color_accent' => ['required_with:theme_tokens', 'string', 'regex:' . TemplateService::HEX_COLOR_REGEX],
            'theme_tokens.font_family' => ['required_with:theme_tokens', 'string', Rule::in(TemplateService::FONT_ALLOWLIST)],
            'theme_tokens.button_radius' => ['required_with:theme_tokens', 'string', Rule::in(TemplateService::BUTTON_RADIUS)],
            'theme_tokens.button_style' => ['required_with:theme_tokens', 'string', Rule::in(TemplateService::BUTTON_STYLES)],
            'theme_tokens.animation' => ['required_with:theme_tokens', 'string', Rule::in(TemplateService::ANIMATIONS)],
            // custom_options is a freeform JSON bag for VCard and template-specific settings.
            // Use 'sometimes' to allow any nested structure (products array, banner_images, social_urls, etc.)
            // without strict scalar-only validation that would silently strip nested arrays.
            'theme_tokens.custom_options' => ['sometimes', 'nullable', 'array'],
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
            'template_id.in' => 'The selected template is invalid. Please select an approved template.',
            'theme_tokens.color_background.regex' => 'Background color must be a valid hex format (#RRGGBB or #RGB).',
            'theme_tokens.color_surface.regex' => 'Surface color must be a valid hex format (#RRGGBB or #RGB).',
            'theme_tokens.color_text_primary.regex' => 'Text primary color must be a valid hex format (#RRGGBB or #RGB).',
            'theme_tokens.color_text_secondary.regex' => 'Text secondary color must be a valid hex format (#RRGGBB or #RGB).',
            'theme_tokens.color_accent.regex' => 'Accent color must be a valid hex format (#RRGGBB or #RGB).',
            'theme_tokens.font_family.in' => 'Font family must be chosen from the approved font allowlist.',
            'theme_tokens.button_radius.in' => 'Button radius must be a valid token enum.',
            'theme_tokens.button_style.in' => 'Button style must be a valid token enum.',
            'theme_tokens.animation.in' => 'Animation mode must be a valid token enum.',
        ];
    }
}
