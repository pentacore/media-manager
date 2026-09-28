<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Ai\Tools\Bazarr\QueueAutomaticReplacementTool;
use App\Models\SubtitleCase;
use App\Support\UpstreamErrorText;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * The Media Advisor's decision for one escalated subtitle case, made in code:
 * queue the unique automatic replacement candidate when the server-side
 * inspection offers one, otherwise hand the case to a human. This was a fixed
 * rule wrapped in an LLM run; it now costs no model call.
 *
 * Queueing goes through QueueAutomaticReplacementTool, so its re-validation
 * (requirements fingerprint, candidate fingerprint, run boundary), BaseTool's
 * advisory-mode gate and the deferred, approval-gated ActionRequest stay the
 * one queueing path. The caller binds the SubtitleAdvisorRunContext; the tool
 * records the queued action there.
 */
class SubtitleAdvisorDecider
{
    public function __construct(private readonly SubtitleAdvisorProjection $subtitleAdvisorProjection) {}

    /**
     * Decide the case and return its audit summary.
     */
    public function decide(SubtitleCase $subtitleCase): string
    {
        try {
            $replacementContext = $this->subtitleAdvisorProjection->replacementContextForCase($subtitleCase);
        } catch (Throwable $throwable) {
            Log::warning('Subtitle Advisor inspection failed.', [
                'subtitle_case_id' => $subtitleCase->id,
                'exception' => $throwable::class,
                'message' => UpstreamErrorText::sanitize($throwable->getMessage()),
            ]);

            return sprintf(
                'The Media Advisor could not inspect subtitle case #%d: %s A human needs to review it.',
                $subtitleCase->id,
                UpstreamErrorText::sanitize($throwable->getMessage()),
            );
        }

        $projection = $replacementContext['projection'];
        $displayName = (string) $projection['display_name'];
        $languages = $projection['required_languages'] === [] ? 'none' : implode(', ', $projection['required_languages']);
        $automaticCandidate = $projection['replacement']['automatic_candidate'];

        if (! is_array($automaticCandidate) || ($automaticCandidate['fingerprint'] ?? '') === '') {
            return sprintf(
                'No unique automatic replacement candidate for %s (required subtitles: %s; %d candidate(s) inspected). A human needs to pick a release or resolve it manually.',
                $displayName,
                $languages,
                (int) $projection['replacement']['candidate_count'],
            );
        }

        $result = json_decode((string) resolve(QueueAutomaticReplacementTool::class)->handle(new Request([
            'case_id' => $subtitleCase->id,
            'candidate_fingerprint' => $automaticCandidate['fingerprint'],
            'reason' => sprintf('Bazarr exhausted its retries without the required subtitles (%s); the automatic-selection rules chose this unique candidate.', $languages),
        ])), true);

        if (is_array($result) && ($result['queued'] ?? false) === true) {
            return sprintf(
                'Queued the unique automatic replacement candidate "%s" for %s (required subtitles: %s). The replacement is requested, not yet verified.',
                $automaticCandidate['title'] !== '' ? $automaticCandidate['title'] : $automaticCandidate['fingerprint'],
                $displayName,
                $languages,
            );
        }

        return sprintf(
            'The automatic replacement candidate for %s was not queued (%s). A human needs to review the case.',
            $displayName,
            is_array($result) ? (string) ($result['reason'] ?? $result['error'] ?? 'unknown') : 'unreadable tool result',
        );
    }
}
