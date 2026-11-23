<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\SeasonPackPolicy;
use App\Enums\SubtitleRuleStrength;
use App\Settings\MediaReplacementSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

// The deeply nested `media_replacement.*` wildcard rules cannot be expressed as
// valid TypeScript by Typefinder's request extractor, and the field is posted as
// a JSON string anyway; the Vue editor owns the concrete shape.
#[TypefinderOverrides(['media_replacement' => 'Record<string, unknown>'])]
class UpdateMediaReplacementSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'media_replacement' => ['required', 'array'],
            'media_replacement.automatic_selection_enabled' => ['required', 'boolean'],
            'media_replacement.automatic_selection_threshold' => ['required', 'integer', 'between:0,100'],
            'media_replacement.global_languages' => ['required', 'array', 'min:1'],
            'media_replacement.global_languages.*' => ['required', 'string', 'max:50'],
            'media_replacement.scoped_languages' => ['required', 'array:anime,tv,movie'],
            'media_replacement.scoped_languages.*' => ['nullable', 'array'],
            'media_replacement.scoped_languages.*.*' => ['required', 'string', 'max:50'],
            'media_replacement.season_pack_policy' => ['required', SeasonPackPolicy::validationRule()],
            'media_replacement.subtitle_check' => ['required', 'array:enabled,max_attempts_per_target,cooldown_hours'],
            'media_replacement.subtitle_check.enabled' => ['required', 'boolean'],
            'media_replacement.subtitle_check.max_attempts_per_target' => ['required', 'integer', 'min:1', 'max:10'],
            'media_replacement.subtitle_check.cooldown_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'media_replacement.subtitle_audit' => ['required', 'array:'.implode(',', array_keys(MediaReplacementSettings::SUBTITLE_AUDIT_BOUNDS))],
            'media_replacement.subtitle_audit.search_minutes_per_episode' => ['required', 'integer', ...$this->subtitleAuditBounds('search_minutes_per_episode')],
            'media_replacement.subtitle_audit.min_search_minutes' => ['required', 'integer', ...$this->subtitleAuditBounds('min_search_minutes'), 'lte:media_replacement.subtitle_audit.max_search_minutes'],
            'media_replacement.subtitle_audit.max_search_minutes' => ['required', 'integer', ...$this->subtitleAuditBounds('max_search_minutes'), 'gte:media_replacement.subtitle_audit.min_search_minutes'],
            'media_replacement.subtitle_audit.in_chat_wait_seconds' => ['required', 'integer', ...$this->subtitleAuditBounds('in_chat_wait_seconds')],
            // The watchdog must outlast the request that started the batch.
            'media_replacement.subtitle_audit.watchdog_delay_seconds' => [
                'required',
                'integer',
                'min:'.max(MediaReplacementSettings::SUBTITLE_AUDIT_BOUNDS['watchdog_delay_seconds'][0], MediaReplacementSettings::requestCeilingSeconds() + 1),
                'max:'.MediaReplacementSettings::SUBTITLE_AUDIT_BOUNDS['watchdog_delay_seconds'][1],
            ],
            'media_replacement.subtitle_audit.sweep_grace_minutes' => ['required', 'integer', ...$this->subtitleAuditBounds('sweep_grace_minutes')],
            'media_replacement.guidance' => ['required', 'array:anime,tv,movie'],
            'media_replacement.guidance.*.notes' => ['present', 'string', 'max:4000'],
            'media_replacement.guidance.*.rules' => ['present', 'array', 'max:50'],
            'media_replacement.guidance.*.rules.*.name' => ['required', 'string', 'max:100'],
            'media_replacement.guidance.*.rules.*.enabled' => ['required', 'boolean'],
            'media_replacement.guidance.*.rules.*.strength' => ['required', SubtitleRuleStrength::validationRule()],
            'media_replacement.guidance.*.rules.*.languages' => ['required', 'array', 'min:1'],
            'media_replacement.guidance.*.rules.*.languages.*' => ['required', 'string', 'max:50'],
            'media_replacement.guidance.*.rules.*.conditions' => ['required', 'array', 'min:1', 'max:10'],
            'media_replacement.guidance.*.rules.*.conditions.*.field' => ['required', 'in:release_group,subgroup,title,custom_format'],
            'media_replacement.guidance.*.rules.*.conditions.*.value' => ['required', 'string', 'max:150'],
            'media_replacement.guidance.*.rules.*.explanation' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  key-of<MediaReplacementSettings::SUBTITLE_AUDIT_BOUNDS>  $key
     * @return array{string, string}
     */
    private function subtitleAuditBounds(string $key): array
    {
        [$minimum, $maximum] = MediaReplacementSettings::SUBTITLE_AUDIT_BOUNDS[$key];

        return ['min:'.$minimum, 'max:'.$maximum];
    }

    /**
     * Decode the `media_replacement` JSON string submitted by the settings
     * form into an array. A malformed JSON string becomes an empty array so
     * the nested rules surface normal validation errors; an entirely absent
     * field falls back to the stored configuration so partial updates are
     * safe.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        if (! $this->has('media_replacement')) {
            $this->merge([
                'media_replacement' => resolve(MediaReplacementSettings::class)->configuration(),
            ]);

            return;
        }

        $mediaReplacement = $this->input('media_replacement');

        if (is_string($mediaReplacement)) {
            try {
                $decoded = json_decode($mediaReplacement, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = [];
            }

            $this->merge(['media_replacement' => is_array($decoded) ? $decoded : []]);
        }
    }
}
