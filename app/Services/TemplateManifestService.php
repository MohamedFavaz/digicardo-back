<?php

namespace App\Services;

use RuntimeException;

/**
 * Loads template manifests from resources/templates/*.json (ADR-031).
 *
 * A manifest describes what a template shows (`sections`), its default theme, the
 * template-specific style controls the editor generates (`controls`) and one-click
 * colour presets. The frontend keeps a copy in src/templates/manifests/; a contract
 * test fails when the two differ.
 */
class TemplateManifestService
{
    public const CONTROL_TYPES = ['toggle', 'choice', 'color'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifests = null;

    /**
     * @return array<string, array<string, mixed>> Keyed by template id, in file-name order.
     */
    public function all(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $manifests = [];
        $files = glob(resource_path('templates/*.json')) ?: [];
        sort($files);

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            $manifest = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($manifest) || !isset($manifest['id']) || !is_string($manifest['id'])) {
                throw new RuntimeException("Invalid template manifest: {$file}");
            }
            $manifests[$manifest['id']] = $manifest;
        }

        // Classic (vcard) is the default template, so list it first.
        uksort($manifests, fn (string $a, string $b) => ($b === 'vcard') <=> ($a === 'vcard') ?: strcmp($a, $b));

        return $this->manifests = $manifests;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $templateId): ?array
    {
        return $this->all()[$templateId] ?? null;
    }

    /**
     * Style controls of a template, keyed by control key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function controls(string $templateId): array
    {
        $controls = [];
        foreach (($this->find($templateId)['controls'] ?? []) as $control) {
            if (is_array($control) && isset($control['key'], $control['type']) && in_array($control['type'], self::CONTROL_TYPES, true)) {
                $controls[$control['key']] = $control;
            }
        }

        return $controls;
    }

    /**
     * Keeps only template_style values that match the template's controls; unknown keys,
     * wrong types and values outside a choice list are removed.
     *
     * @param  array<mixed>  $style
     * @return array<string, string|bool>
     */
    public function sanitizeStyle(string $templateId, array $style): array
    {
        $clean = [];

        foreach ($this->controls($templateId) as $key => $control) {
            if (!array_key_exists($key, $style)) {
                continue;
            }
            $value = $style[$key];

            switch ($control['type']) {
                case 'toggle':
                    if (is_bool($value)) {
                        $clean[$key] = $value;
                    }
                    break;
                case 'choice':
                    $allowed = array_column($control['options'] ?? [], 'value');
                    if (is_string($value) && in_array($value, $allowed, true)) {
                        $clean[$key] = $value;
                    }
                    break;
                case 'color':
                    if (is_string($value) && preg_match(TemplateService::HEX_COLOR_REGEX, $value) === 1) {
                        $clean[$key] = $value;
                    }
                    break;
            }
        }

        return $clean;
    }

    /**
     * Preset ids of a template.
     *
     * @return list<string>
     */
    public function presetIds(string $templateId): array
    {
        return array_values(array_filter(array_map(
            fn ($preset) => is_array($preset) ? ($preset['id'] ?? null) : null,
            $this->find($templateId)['presets'] ?? []
        ), 'is_string'));
    }
}
