<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AiReasoningLevel;
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
            'model' => ['required', 'string', 'max:100'],
            'event_allowlist' => ['present', 'array'],
            'event_allowlist.*' => ['string', Rule::in(DecisionAgentSettings::availableEventKeys())],
            'allow_manual_import' => ['required', 'boolean'],
            'notify_on_suggest' => ['required', 'boolean'],
            'notify_on_act' => ['required', 'boolean'],
            'max_actions_per_run' => ['required', 'integer', 'min:1', 'max:20'],
            'reasoning_level' => ['required', AiReasoningLevel::validationRule()],
        ];
    }
}
