<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Ai\Classification\Classifier;
use App\Enums\SecondOpinionEngine;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'second_opinion_engine.in' => 'Configure a classification provider on AI Settings before using Jev.',
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
}
