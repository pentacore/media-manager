<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Decision\DecisionPrompt;
use App\Ai\Decision\SecondOpinionCase;
use App\Ai\Decision\SecondOpinionVerifier;
use App\Models\ActionTypeConfig;
use App\Services\Actions\ActionDescription;

/**
 * A structured decision verifier case: the fields an admin fills in, stored
 * as entered and turned into the exact case file production would render.
 *
 * @phpstan-type VerifierFields array{action_type: string, action_label: string, title: string, description: string, details: list<array{label: string, value: string}>, verified: bool, payload_json: string, rationale: string, source_service: string, event_type: string|null, event_payload_json: string}
 */
final readonly class VerifierCaseInput
{
    /**
     * @param  array<string, mixed>  $fields  validated request fields
     * @return VerifierFields
     */
    public static function normalize(array $fields): array
    {
        $details = [];

        foreach (is_array($fields['details'] ?? null) ? $fields['details'] : [] as $detail) {
            $details[] = ['label' => (string) $detail['label'], 'value' => (string) $detail['value']];
        }

        $eventType = (string) ($fields['event_type'] ?? '');
        $payloadJson = (string) ($fields['payload_json'] ?? '');
        $eventPayloadJson = (string) ($fields['event_payload_json'] ?? '');

        return [
            'action_type' => (string) $fields['action_type'],
            'action_label' => (string) $fields['action_label'],
            'title' => (string) $fields['title'],
            'description' => (string) $fields['description'],
            'details' => $details,
            'verified' => (bool) $fields['verified'],
            'payload_json' => $payloadJson === '' ? '{}' : $payloadJson,
            'rationale' => (string) ($fields['rationale'] ?? ''),
            'source_service' => (string) $fields['source_service'],
            'event_type' => $eventType === '' ? null : $eventType,
            'event_payload_json' => $eventPayloadJson === '' ? '{}' : $eventPayloadJson,
        ];
    }

    /**
     * @param  VerifierFields  $verifier
     */
    public static function secondOpinionCase(array $verifier): SecondOpinionCase
    {
        return new SecondOpinionCase(
            $verifier['action_type'],
            $verifier['action_label'],
            new ActionDescription($verifier['title'], $verifier['description'], $verifier['details'], $verifier['verified']),
            DecisionPrompt::payloadFromJson($verifier['payload_json']) ?? [],
            $verifier['rationale'],
            $verifier['source_service'],
            $verifier['event_type'],
            DecisionPrompt::payloadFromJson($verifier['event_payload_json']) ?? [],
        );
    }

    /**
     * @param  VerifierFields  $verifier
     */
    public static function prompt(array $verifier): string
    {
        return self::secondOpinionCase($verifier)->toPrompt();
    }

    /**
     * Every supported action type with its rule label, the type itself when
     * no rule is configured.
     *
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        $types = SecondOpinionVerifier::supportedActionTypes();

        return [
            ...array_combine($types, $types),
            ...ActionTypeConfig::query()->whereIn('type', $types)->pluck('label', 'type')->all(),
        ];
    }
}
