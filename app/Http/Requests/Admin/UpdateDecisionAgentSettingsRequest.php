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
use Illuminate\Validation\Validator;
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
            'second_opinion_engine' => ['required', 'string', SecondOpinionEngine::validationRule()],
            'second_opinion_jev_threshold' => ['required', 'numeric', 'min:0', 'max:1'],
            'second_opinion_event_overrides' => ['sometimes', 'array', 'list'],
            'second_opinion_event_overrides.*' => ['array:event_key,mode,engine,jev_threshold'],
            'second_opinion_event_overrides.*.event_key' => ['required', 'string', 'distinct', Rule::in(DecisionAgentSettings::availableEventKeys())],
            'second_opinion_event_overrides.*.mode' => ['nullable', 'string', SecondOpinionMode::validationRule()],
            'second_opinion_event_overrides.*.jev_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'second_opinion_event_overrides.*.engine' => ['nullable', 'string', SecondOpinionEngine::validationRule()],
        ];
    }

    /**
     * Readable names for the per-row override fields, so their errors fit a
     * phone screen instead of naming the raw `second_opinion_event_overrides.N.*` key.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'second_opinion_event_overrides.*.event_key' => 'event',
            'second_opinion_event_overrides.*.mode' => 'mode',
            'second_opinion_event_overrides.*.engine' => 'engine',
            'second_opinion_event_overrides.*.jev_threshold' => 'Jev threshold',
        ];
    }

    /**
     * The field rules accept every engine; whether Jev is allowed follows the
     * classifier's API key and the stored settings, so it is checked here
     * (a rule-level list would make the generated request type differ per .env).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $message = 'Configure a classification provider on AI Settings before using Jev.';
            $engine = $this->input('second_opinion_engine');

            if (is_string($engine) && ! $validator->errors()->has('second_opinion_engine') && ! in_array($engine, $this->allowedSecondOpinionEngines(), true)) {
                $validator->errors()->add('second_opinion_engine', $message);
            }

            $overrides = $this->input('second_opinion_event_overrides');

            if (! is_array($overrides)) {
                return;
            }

            foreach ($overrides as $index => $override) {
                $key = sprintf('second_opinion_event_overrides.%s.engine', $index);
                $overrideEngine = data_get($override, 'engine');
                $eventKey = data_get($override, 'event_key');

                if (is_string($overrideEngine) && ! $validator->errors()->has($key) && ! in_array($overrideEngine, $this->allowedEventOverrideEngines(is_string($eventKey) ? $eventKey : null), true)) {
                    $validator->errors()->add($key, $message);
                }
            }
        }];
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
            return array_map(static fn (SecondOpinionEngine $engine): string => $engine->value, SecondOpinionEngine::cases());
        }

        return array_values(array_unique([
            SecondOpinionEngine::Llm->value,
            resolve(DecisionAgentSettings::class)->secondOpinionEngine()->value,
        ]));
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
            return array_map(static fn (SecondOpinionEngine $engine): string => $engine->value, SecondOpinionEngine::cases());
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
