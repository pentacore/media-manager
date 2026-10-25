<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Decision\DecisionPrompt;
use App\Ai\Routing\DecisionActionKind;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\AgentDecision;
use App\Models\AiUsageRecord;
use App\Models\ClassificationOutcome;
use App\Settings\DecisionAgentSettings;

/**
 * Rebuilds the webhook event and the gate scope a recorded DecisionAgent run
 * saw, so a benchmark case reproduces the real run.
 */
final readonly class DecisionCaseCapture
{
    public const string SCOPE_WARNING = 'The gate scope of this run was not recorded; the case runs with the full toolset.';

    public const string PAYLOAD_WARNING = 'The event payload could not be recovered in full, so the case keeps the recorded prompt as free text.';

    public const string PAYLOAD_TOO_LARGE_WARNING = 'The event payload is too large to store as an event; the case keeps the recorded prompt.';

    public function capture(AiUsageRecord $aiUsageRecord, string $prompt): DecisionCaseDraft
    {
        $decision = AgentDecision::query()->with('webhookEvent')->where('invocation_id', $aiUsageRecord->invocation_id)->latest('id')->first();
        $parsed = DecisionPrompt::parse($prompt);

        $service = $decision->service ?? $parsed['service'] ?? null;
        $eventType = $decision->event_type ?? $parsed['event_type'] ?? null;

        if ($service === null || $eventType === null) {
            return new DecisionCaseDraft(['prompt' => $prompt], [], false, null);
        }

        $webhookEvent = $decision?->webhookEvent;
        $payloadJson = $webhookEvent !== null ? DecisionPrompt::payloadJson($webhookEvent->payload) : $this->emptyAsObject($parsed['payload_json'] ?? null);
        $payload = $payloadJson === null ? null : DecisionPrompt::payloadFromJson($payloadJson);
        $eventKey = DecisionAgentSettings::eventKey($service, $eventType);
        $eventLabel = sprintf('%s %s', $service, $eventType);

        $tooLarge = $payloadJson !== null && mb_strlen($payloadJson) > DecisionPrompt::MAX_STORED_PAYLOAD_CHARS;

        if ($payloadJson === null || $payload === null || $tooLarge) {
            $input = ['prompt' => $prompt];

            if (in_array($eventKey, DecisionAgentSettings::availableEventKeys(), true)) {
                $input['event_key'] = $eventKey;
            }

            $warning = $tooLarge ? self::PAYLOAD_TOO_LARGE_WARNING : self::PAYLOAD_WARNING;

            return new DecisionCaseDraft($input, [$warning, self::SCOPE_WARNING], true, $eventLabel);
        }

        $warnings = [];
        $actionKind = null;

        if ($eventType !== 'ManualInteractionRequired') {
            $actionKind = $this->scopedActionKind($decision);

            if ($actionKind === false) {
                $actionKind = null;
                $warnings[] = self::SCOPE_WARNING;
            }
        }

        return new DecisionCaseDraft([
            'prompt' => DecisionPrompt::build($service, $eventType, $payload),
            'event' => ['service' => $service, 'event_type' => $eventType, 'payload_json' => $payloadJson],
            'action_kind' => $actionKind,
            'event_key' => $eventKey,
        ], $warnings, false, $eventLabel);
    }

    /**
     * A recorded empty payload is printed as a list; it is stored as an object.
     */
    private function emptyAsObject(?string $payloadJson): ?string
    {
        return $payloadJson === '[]' ? '{}' : $payloadJson;
    }

    /**
     * The recorded gate scope: a kind, null for the full toolset, false when
     * nothing was recorded.
     */
    private function scopedActionKind(?AgentDecision $decision): string|false|null
    {
        if ($decision?->webhook_event_id === null) {
            return false;
        }

        $outcome = ClassificationOutcome::query()
            ->where('gate', ClassificationGate::ActionKind)
            ->where('subject_key', ClassificationOutcome::subjectKey('decision', $decision->webhook_event_id))
            ->where('question', 'action_kind')
            ->latest('id')
            ->first();

        if ($outcome === null) {
            return false;
        }

        return match ($outcome->verdict) {
            ClassificationVerdict::Scoped => DecisionActionKind::tryFrom((string) $outcome->predicted)?->value,
            ClassificationVerdict::Unscoped => null,
            default => false,
        };
    }
}
