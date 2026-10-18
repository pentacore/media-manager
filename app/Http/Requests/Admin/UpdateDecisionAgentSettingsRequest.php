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
     * Jev engines need a classification provider with a key.
     *
     * @return list<string>
     */
    private function allowedSecondOpinionEngines(): array
    {
        return resolve(Classifier::class)->isConfigured()
            ? SecondOpinionEngine::values()
            : [SecondOpinionEngine::Llm->value];
    }
}
