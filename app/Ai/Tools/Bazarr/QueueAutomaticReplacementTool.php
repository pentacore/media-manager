<?php

declare(strict_types=1);

namespace App\Ai\Tools\Bazarr;

use App\Ai\Risk;
use App\Ai\SubtitleAdvisor\SubtitleAdvisorRunContext;
use App\Ai\Tools\BaseTool;
use App\Models\ActionRequest;
use App\Models\SubtitleCase;
use App\Services\Bazarr\SubtitleAdvisorProjection;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Services\MediaReplacement\MediaReplacementActionPayload;
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

        $matchedRules = is_array($automatic['matched_rules'] ?? null)
            ? array_values($automatic['matched_rules'])
            : [];
        $target = $replacementContext['target'];

        return [
            'type' => 'replace_media_file',
            'source_service' => 'subtitle_advisor',
            'target_service' => (string) ($target['service'] ?? ''),
            'force_requires_approval' => ($automatic['requires_approval'] ?? false) === true,
            'payload' => resolve(MediaReplacementActionPayload::class)->build(
                target: $target,
                candidate: $automatic,
                effectiveLanguages: $replacementContext['effective_languages'],
                matchedRules: $matchedRules,
                selectionMode: 'automatic',
                reason: $reason,
                subtitleCaseId: $subtitleCase->id,
            ),
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
        $this->context()->recordQueued($actionRequest->id);
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
