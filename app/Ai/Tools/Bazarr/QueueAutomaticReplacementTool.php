<?php

declare(strict_types=1);

namespace App\Ai\Tools\Bazarr;

use App\Ai\Risk;
use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Ai\Tools\BaseTool;
use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Services\Bazarr\SubtitleAdvisorProjection;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Services\Bazarr\SubtitleCaseLifecycle;
use App\Services\MediaReplacement\ReplacementRequestBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Override;

final class QueueAutomaticReplacementTool extends BaseTool
{
    public function description(): string
    {
        return 'Queue the exact unique automatic replacement candidate for the one subtitle case bound to this Advisor run. '
            .'The server re-inspects the file, requirements, and candidate before creating one Action Request.';
    }

    public function risk(): Risk
    {
        return Risk::Destructive;
    }

    /**
     * @return array{
     *     type: string,
     *     source_service: string,
     *     target_service: string,
     *     force_requires_approval: bool,
     *     defer_execution: true,
     *     payload: array<string, mixed>
     * }
     */
    protected function execute(Request $request): array
    {
        $arguments = $request->toArray();
        $caseId = (int) ($arguments['case_id'] ?? 0);
        $requestedFingerprint = (string) ($arguments['candidate_fingerprint'] ?? '');
        $reason = trim((string) ($arguments['reason'] ?? ''));
        $context = $this->context();

        throw_unless(
            $caseId > 0 && $caseId === $context->caseId && ! $context->capReached(),
            InvalidArgumentException::class,
            'The replacement request is outside this Advisor run boundary.',
        );

        $subtitleCase = SubtitleCase::query()->findOrFail($caseId);
        $requiredLanguages = array_map(
            static fn (mixed $requirement): mixed => is_array($requirement)
                ? ($requirement['code'] ?? null)
                : $requirement,
            $subtitleCase->required_languages,
        );
        $freshRequirementsFingerprint = resolve(SubtitleCaseFingerprint::class)
            ->requirements($subtitleCase->scope, $requiredLanguages);

        throw_unless(
            hash_equals($subtitleCase->requirements_fingerprint, $freshRequirementsFingerprint),
            InvalidArgumentException::class,
            'The subtitle requirements changed after this case was observed.',
        );

        $replacementContext = resolve(SubtitleAdvisorProjection::class)
            ->replacementContextForCase($subtitleCase);
        $automatic = $replacementContext['automatic_candidate'];

        throw_unless(
            is_array($automatic),
            InvalidArgumentException::class,
            'No unique automatic candidate is available.',
        );
        throw_unless(
            $requestedFingerprint !== ''
                && is_string($automatic['fingerprint'] ?? null)
                && hash_equals($automatic['fingerprint'], $requestedFingerprint),
            InvalidArgumentException::class,
            'The automatic candidate changed.',
        );
        throw_if($reason === '', InvalidArgumentException::class, 'A concise replacement reason is required.');

        $target = $replacementContext['target'];

        // Shared with the arr tool and the automatic subtitle check, so all three
        // dispatch an identically shaped payload. The builder also owns
        // force_requires_approval; it cannot differ from the value computed here
        // before, because automaticCandidate() only ever returns a candidate whose
        // requires_approval is false, and returns null for a season pack unless the
        // policy is AutomaticAboveThreshold — so neither operand can be true.
        $built = resolve(ReplacementRequestBuilder::class)->build(
            snapshot: $target,
            candidate: $automatic,
            requiredLanguages: $replacementContext['effective_languages'],
            selectionMode: 'automatic',
            reason: $reason,
            subtitleCaseId: $subtitleCase->id,
        );

        return [
            'type' => 'replace_media_file',
            'source_service' => 'subtitle_advisor',
            'target_service' => (string) ($target['service'] ?? ''),
            'force_requires_approval' => $built['force_requires_approval'],
            'defer_execution' => true,
            'payload' => $built['payload'],
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'case_id' => $schema->integer()
                ->description('The exact subtitle case id supplied in the run prompt.')
                ->required(),
            'candidate_fingerprint' => $schema->string()
                ->description('The exact fingerprint returned as automatic_candidate by the inspection tool.')
                ->required(),
            'reason' => $schema->string()
                ->description('A concise audit rationale for requesting this automatic replacement.')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    #[Override]
    protected function actionRequestQueued(ActionRequest $actionRequest, array $candidate): void
    {
        $context = $this->context();
        $subtitleCase = SubtitleCase::query()->lockForUpdate()->find($context->caseId);
        $attempt = SubtitleCaseAttempt::query()
            ->where('subtitle_case_id', $context->caseId)
            ->where('type', SubtitleCaseAttemptType::Advisor)
            ->where('outcome', SubtitleCaseAttemptOutcome::Started)
            ->whereNull('completed_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        throw_unless(
            $subtitleCase instanceof SubtitleCase
                && $subtitleCase->status === SubtitleCaseStatus::AdvisorRunning
                && $attempt instanceof SubtitleCaseAttempt
                && (int) ($actionRequest->payload['subtitle_case_id'] ?? 0) === $subtitleCase->id,
            InvalidArgumentException::class,
            'No active subtitle Advisor attempt can own this replacement.',
        );

        throw_unless(
            resolve(SubtitleCaseLifecycle::class)->transition(
                $subtitleCase,
                SubtitleCaseStatus::ReplacementRequested,
                ['replacement_action_request_id' => $actionRequest->id],
            ),
            InvalidArgumentException::class,
            'The subtitle case could not be linked to this replacement.',
        );

        $attempt->forceFill([
            'action_request_id' => $actionRequest->id,
            'candidate_count' => 1,
            'eligible_candidate_count' => 1,
        ])->save();

        $context->recordQueued($actionRequest->id);
    }

    private function context(): SubtitleAdvisorRunContext
    {
        $context = app()->bound(SubtitleAdvisorRunContext::class)
            ? resolve(SubtitleAdvisorRunContext::class)
            : null;

        throw_unless(
            $context instanceof SubtitleAdvisorRunContext,
            InvalidArgumentException::class,
            'No subtitle Advisor run context is bound.',
        );

        return $context;
    }
}
