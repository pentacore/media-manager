<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Decision\DecisionPrompt;
use App\Ai\Decision\SecondOpinionGroundTruth;
use App\Enums\AiTask;
use App\Enums\BenchmarkScoringType;
use App\Models\ActionRequest;
use App\Models\AiUsageRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Rebuilds the case file a recorded second opinion saw from its action
 * request, and the verdict a person ruled on that action.
 */
final readonly class VerifierCaseCapture
{
    public const string NO_REQUEST_WARNING = 'The action request behind this second opinion is gone, so the case keeps the recorded prompt; choose the expected verdict.';

    public const string NO_REVIEW_WARNING = 'No person has reviewed this action, so the expected verdict is not pre-filled; choose it before saving.';

    public const string NO_EVENT_WARNING = 'The triggering webhook event is gone, so the event type and payload are empty.';

    public const string PROMPT_DIFFERS_WARNING = 'The rebuilt case file differs from the prompt the verifier saw; check the preview before saving. Switch to Raw prompt to keep the recorded case file.';

    public const string PAYLOAD_TOO_LARGE_WARNING = 'The action or event payload is too large to store as a structured case; the case keeps the rebuilt case file as its prompt.';

    public const string NO_DESCRIPTION_WARNING = 'This action request has no description to rebuild the case from, so the case keeps the recorded prompt; choose the expected verdict.';

    public const string NO_SECOND_OPINION = 'This action request has no second opinion to benchmark.';

    public const string FAILED_SECOND_OPINION = 'This second opinion failed, so it has no verdict to benchmark.';

    public const string NO_DESCRIPTION = 'This action request has no description to rebuild the case from.';

    private const int NAME_TITLE_CHARS = 60;

    public function fromUsage(AiUsageRecord $aiUsageRecord, string $prompt): VerifierCaseDraft
    {
        $actionRequest = ActionRequest::query()
            ->where('second_opinion->invocation_id', $aiUsageRecord->invocation_id)
            ->latest('id')
            ->first();

        if ($actionRequest === null) {
            return new VerifierCaseDraft(['prompt' => $prompt], null, [self::NO_REQUEST_WARNING], null);
        }

        if (blank($actionRequest->title) || blank($actionRequest->description)) {
            return new VerifierCaseDraft(['prompt' => $prompt], null, [self::NO_DESCRIPTION_WARNING], null);
        }

        return $this->rebuild($actionRequest, $aiUsageRecord);
    }

    /**
     * @return array{draft: array<string, mixed>, warnings: list<string>}
     *
     * @throws CaseDraftRefused
     */
    public function draft(ActionRequest $actionRequest): array
    {
        $secondOpinion = $actionRequest->second_opinion;

        throw_if(! is_array($secondOpinion) || ! is_bool($secondOpinion['agrees'] ?? null), CaseDraftRefused::class, self::NO_SECOND_OPINION);
        throw_if(($secondOpinion['failed'] ?? false) === true, CaseDraftRefused::class, self::FAILED_SECOND_OPINION);
        throw_if(blank($actionRequest->title) || blank($actionRequest->description), CaseDraftRefused::class, self::NO_DESCRIPTION);

        $aiUsageRecord = $this->usageOf($actionRequest);
        $verifierCaseDraft = $this->rebuild($actionRequest, $aiUsageRecord);

        return [
            'draft' => [
                'task' => AiTask::DecisionVerifier->value,
                'name' => $verifierCaseDraft->name,
                'tags' => [],
                'notes' => null,
                'input' => $verifierCaseDraft->input,
                'draft_attachments' => [],
                'fixtures' => [],
                'scoring' => ['type' => BenchmarkScoringType::Verdict->value, 'expected_agrees' => $verifierCaseDraft->expectedAgrees],
                'source_invocation_id' => $aiUsageRecord?->invocation_id,
                'source_prompt_tokens' => $aiUsageRecord?->prompt_tokens,
                'source_completion_tokens' => $aiUsageRecord?->completion_tokens,
                'source_truncated' => false,
            ],
            'warnings' => $verifierCaseDraft->warnings,
        ];
    }

    private function usageOf(ActionRequest $actionRequest): ?AiUsageRecord
    {
        $invocationId = $actionRequest->second_opinion['invocation_id'] ?? null;

        if (! is_string($invocationId) || $invocationId === '') {
            return null;
        }

        return AiUsageRecord::query()->where('invocation_id', $invocationId)->latest('id')->first();
    }

    private function rebuild(ActionRequest $actionRequest, ?AiUsageRecord $aiUsageRecord): VerifierCaseDraft
    {
        $actionRequest->loadMissing('webhookEvent');

        $payload = $actionRequest->payload ?? [];
        $webhookEvent = $actionRequest->webhookEvent;
        $warnings = [];

        $verifier = [
            'action_type' => $actionRequest->type,
            'action_label' => VerifierCaseInput::actionLabels()[$actionRequest->type] ?? $actionRequest->type,
            'title' => (string) $actionRequest->title,
            'description' => (string) $actionRequest->description,
            'details' => $actionRequest->details ?? [],
            'verified' => $actionRequest->description_verified,
            'payload_json' => DecisionPrompt::payloadJson(Arr::except($payload, ['agent_rationale'])),
            'rationale' => is_string($payload['agent_rationale'] ?? null) ? $payload['agent_rationale'] : '',
            'source_service' => (string) $actionRequest->source_service,
            'event_type' => $webhookEvent?->event_type,
            'event_payload_json' => $webhookEvent !== null ? DecisionPrompt::payloadJson($webhookEvent->payload) : '{}',
        ];

        if ($webhookEvent === null) {
            $warnings[] = self::NO_EVENT_WARNING;
        }

        $rebuiltPrompt = VerifierCaseInput::prompt($verifier);

        $recorded = $aiUsageRecord?->prompt_text;
        $recordedDiffers = filled($recorded) && ! str_ends_with((string) $recorded, '…') && $recorded !== $rebuiltPrompt;

        if (mb_strlen($verifier['payload_json']) > DecisionPrompt::MAX_STORED_PAYLOAD_CHARS || mb_strlen($verifier['event_payload_json']) > DecisionPrompt::MAX_STORED_PAYLOAD_CHARS) {
            $input = ['prompt' => $rebuiltPrompt];
            $warnings[] = self::PAYLOAD_TOO_LARGE_WARNING;
        } else {
            $input = ['prompt' => $recordedDiffers ? $recorded : $rebuiltPrompt, 'verifier' => $verifier];
        }

        if ($recordedDiffers) {
            $warnings[] = self::PROMPT_DIFFERS_WARNING;
        }

        $expectedAgrees = SecondOpinionGroundTruth::expectedAgrees($actionRequest->status, $actionRequest->approved_by, $actionRequest->second_opinion);

        if ($expectedAgrees === null) {
            $warnings[] = self::NO_REVIEW_WARNING;
        }

        return new VerifierCaseDraft(
            $input,
            $expectedAgrees,
            $warnings,
            sprintf('%s: %s', AiTask::DecisionVerifier->label(), Str::limit((string) $actionRequest->title, self::NAME_TITLE_CHARS)),
        );
    }
}
