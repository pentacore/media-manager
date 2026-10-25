<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Ai\Decision\DecisionPrompt;
use App\Services\Actions\ActionDescription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Validator;

/**
 * Field shapes of a structured decision verifier case (the proposed action as
 * the approver sees it, the rationale and the triggering event), plus the
 * check that both payload texts are JSON objects.
 */
trait VerifierCaseValidationRules
{
    /**
     * @param  string  $prefix  the case's dotted path with a trailing dot, e.g. `input.verifier.`, or empty
     * @return array<string, array<int, RequiredIf|ValidationRule|string>>
     */
    protected function verifierCaseRules(string $prefix, bool $required): array
    {
        $presence = fn (): RequiredIf|string => $required
            ? 'required'
            : Rule::requiredIf(fn (): bool => $this->input(rtrim($prefix, '.')) !== null);

        return [
            $prefix.'action_type' => [$presence(), 'string', 'max:100'],
            $prefix.'action_label' => [$presence(), 'string', 'max:200'],
            $prefix.'title' => [$presence(), 'string', sprintf('max:%d', ActionDescription::TITLE_LIMIT)],
            $prefix.'description' => [$presence(), 'string', sprintf('max:%d', ActionDescription::DESCRIPTION_LIMIT)],
            $prefix.'details' => ['nullable', 'array', sprintf('max:%d', ActionDescription::DETAIL_LIMIT)],
            $prefix.'details.*' => ['array:label,value'],
            $prefix.'details.*.label' => ['required', 'string', sprintf('max:%d', ActionDescription::LABEL_LIMIT)],
            $prefix.'details.*.value' => ['required', 'string', sprintf('max:%d', ActionDescription::VALUE_LIMIT)],
            $prefix.'verified' => [$presence(), 'boolean'],
            $prefix.'payload_json' => [$presence(), 'string', 'max:65536'],
            $prefix.'rationale' => ['nullable', 'string', 'max:1000'],
            $prefix.'source_service' => [$presence(), 'string', 'max:100'],
            $prefix.'event_type' => ['nullable', 'string', 'max:100'],
            $prefix.'event_payload_json' => [$presence(), 'string', 'max:65536'],
        ];
    }

    protected function checkVerifierPayloadObjects(Validator $validator, string $prefix): void
    {
        $messages = [
            'payload_json' => 'The payload must be a JSON object.',
            'event_payload_json' => 'The event payload must be a JSON object.',
        ];

        foreach ($messages as $field => $message) {
            $value = $this->input($prefix.$field);

            if (is_string($value) && ! $validator->errors()->has($prefix.$field) && DecisionPrompt::payloadFromJson($value) === null) {
                $validator->errors()->add($prefix.$field, $message);
            }
        }
    }
}
