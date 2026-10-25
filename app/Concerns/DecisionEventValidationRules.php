<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Ai\Decision\DecisionPrompt;
use App\Settings\DecisionAgentSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Validator;

/**
 * Field shapes of a webhook event (service, event type, payload as JSON text),
 * plus the check that the payload text is a JSON object.
 */
trait DecisionEventValidationRules
{
    /**
     * @param  string  $prefix  the event's dotted path with a trailing dot, e.g. `input.event.`
     * @return array<string, array<int, RequiredIf|ValidationRule|string>>
     */
    protected function decisionEventRules(string $prefix, bool $required): array
    {
        $presence = fn (): RequiredIf|string => $required
            ? 'required'
            : Rule::requiredIf(fn (): bool => $this->input(rtrim($prefix, '.')) !== null);

        return [
            $prefix.'service' => [$presence(), 'string', Rule::in(DecisionAgentSettings::eventServices())],
            $prefix.'event_type' => [$presence(), 'string', 'max:100'],
            $prefix.'payload_json' => [$presence(), 'string', 'max:65536'],
        ];
    }

    protected function checkDecisionPayloadObject(Validator $validator, string $field): void
    {
        $payloadJson = $this->input($field);

        if (is_string($payloadJson) && ! $validator->errors()->has($field) && DecisionPrompt::payloadFromJson($payloadJson) === null) {
            $validator->errors()->add($field, 'The payload must be a JSON object.');
        }
    }
}
