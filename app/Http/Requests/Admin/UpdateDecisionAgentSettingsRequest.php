<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Ai\Classification\Classifier;
use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

#[TypefinderOverrides(['second_opinion_event_overrides' => 'Array<{ event_key: string; mode: string | null; engine: string | null; jev_threshold: number | null }>'])]
class UpdateDecisionAgentSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'event_allowlist' => ['present', 'array'],
            'event_allowlist.*' => ['string', Rule::in(DecisionAgentSettings::availableEventKeys())],
            'allow_manual_import' => ['required', 'boolean'],
            'notify_on_suggest' => ['required', 'boolean'],
            'notify_on_act' => ['required', 'boolean'],
            'max_actions_per_run' => ['required', 'integer', 'min:1', 'max:20'],
            'second_opinion_engine' => ['required', 'string', Rule::in($this->allowedSecondOpinionEngines())],
            'second_opinion_jev_threshold' => ['required', 'numeric', 'min:0', 'max:1'],
            'second_opinion_event_overrides' => ['sometimes', 'array', 'list'],
            'second_opinion_event_overrides.*' => ['array:event_key,mode,engine,jev_threshold'],
            'second_opinion_event_overrides.*.event_key' => ['required', 'string', 'distinct', Rule::in(DecisionAgentSettings::availableEventKeys())],
            'second_opinion_event_overrides.*.mode' => ['nullable', 'string', SecondOpinionMode::validationRule()],
            'second_opinion_event_overrides.*.jev_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            ...$this->eventOverrideEngineRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'second_opinion_engine.in' => 'Configure a classification provider on AI Settings before using Jev.',
            'second_opinion_event_overrides.*.engine.in' => 'Configure a classification provider on AI Settings before using Jev.',
        ];
    }

    /**
     * Switching to a Jev engine needs a classification provider with a key.
     * The stored engine stays allowed without one, so a classifier removed
     * later does not block saving the other settings.
     *
     * @return list<string>
     */
    private function allowedSecondOpinionEngines(): array
    {
        if (resolve(Classifier::class)->isConfigured()) {
            return SecondOpinionEngine::values();
        }

        return array_values(array_unique([
            SecondOpinionEngine::Llm->value,
            resolve(DecisionAgentSettings::class)->secondOpinionEngine()->value,
        ]));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function eventOverrideEngineRules(): array
    {
        $overrides = $this->input('second_opinion_event_overrides');

        if (! is_array($overrides)) {
            return [];
        }

        $rules = [];

        foreach (array_keys($overrides) as $index) {
            $eventKey = data_get($overrides, sprintf('%s.event_key', $index));

            $rules[sprintf('second_opinion_event_overrides.%s.engine', $index)] = [
                'nullable',
                'string',
                Rule::in($this->allowedEventOverrideEngines(is_string($eventKey) ? $eventKey : null)),
            ];
        }

        return $rules;
    }

    /**
     * Same rule as the global engine: Jev needs a classifier, but an engine
     * already stored for the event stays allowed.
     *
     * @return list<string>
     */
    private function allowedEventOverrideEngines(?string $eventKey): array
    {
        if (resolve(Classifier::class)->isConfigured()) {
            return SecondOpinionEngine::values();
        }

        $stored = $eventKey === null
            ? null
            : (resolve(DecisionAgentSettings::class)->secondOpinionEventOverrides()[$eventKey]['engine'] ?? null);

        return array_values(array_unique([
            SecondOpinionEngine::Llm->value,
            ...($stored instanceof SecondOpinionEngine ? [$stored->value] : []),
        ]));
    }
}
