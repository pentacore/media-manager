<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SeasonPackPolicy;
use App\Settings\MediaReplacementSettings;

/**
 * Builds the `replace_media_file` ActionRequest payload and its approval
 * override. Three entry points dispatch the same action — the arr AI tool, the
 * automatic subtitle check, and the Bazarr subtitle advisor — so a second
 * hand-built copy of the shape would drift. Thirteen of the fourteen base
 * payload keys are read by MediaReplacementActions or the approval card;
 * `agent_rationale` is stored for audit only and read by neither.
 *
 * Two optional trailing keys correlate a request back to whichever automation
 * raised it: `auto_check_key` for the automatic check's attempt cap, and
 * `subtitle_case_id` for the advisor's case. They belong to different callers
 * and are never both present. Key set and order are pinned by
 * ReplacementRequestBuilderTest.
 *
 * `force_requires_approval` lives here rather than in each caller because it
 * decides whether a destructive replacement needs a human, and callers that
 * compute it separately can diverge. It only ever tightens the gate — approval
 * is otherwise owned by ActionTypeConfig.
 */
final readonly class ReplacementRequestBuilder
{
    public function __construct(
        private MediaReplacementSettings $mediaReplacementSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $requiredLanguages
     * @param  'automatic'|'manual'  $selectionMode
     * @param  string|null  $autoCheckKey  Per-target key used by the automatic
     *                                     check to enforce its attempt cap; null for
     *                                     operator- and agent-initiated requests.
     * @param  int|null  $subtitleCaseId  The Bazarr advisor case that owns this
     *                                    replacement; null for every other caller.
     * @return array{payload: array<string, mixed>, force_requires_approval: bool}
     */
    public function build(
        array $snapshot,
        array $candidate,
        array $requiredLanguages,
        string $selectionMode,
        string $reason,
        ?string $autoCheckKey = null,
        ?int $subtitleCaseId = null,
    ): array {
        $isSeasonPack = ($candidate['season_pack'] ?? false) === true;
        $candidateRequiresApproval = ($candidate['requires_approval'] ?? false) === true;
        // One bounded reason for both keys. `detail` reaches the approval card and
        // `agent_rationale` the audit trail, and neither wants an unbounded string
        // from a model. `title` is bounded separately because it also has to fit a
        // column, and a long display_name would otherwise overflow it.
        $boundedReason = mb_substr($reason, 0, 1_000);

        $payload = [
            'title' => mb_substr(sprintf('Replace %s', $snapshot['display_name'] ?? 'media file'), 0, 300),
            'detail' => $boundedReason,
            'service' => $snapshot['service'] ?? null,
            'service_connection_id' => $snapshot['service_connection_id'] ?? null,
            'scope' => $snapshot['scope'] ?? null,
            'target' => $snapshot,
            'candidate_fingerprint' => $candidate['fingerprint'] ?? null,
            'candidate' => $candidate,
            'required_languages' => $requiredLanguages,
            'confidence' => $candidate['confidence'] ?? null,
            // Read from the candidate rather than taken as an argument, so the rules
            // reported to the operator cannot disagree with the candidate they were
            // computed for. Normalised to a list: consumers render it as one, and a
            // ranked candidate always carries the key.
            'matched_rules' => array_values($candidate['matched_rules'] ?? []),
            'selection_mode' => $selectionMode,
            'agent_rationale' => $boundedReason,
            'original_history_id' => $snapshot['original_history_id'] ?? null,
        ];

        if ($autoCheckKey !== null) {
            $payload['auto_check_key'] = $autoCheckKey;
        }

        if ($subtitleCaseId !== null) {
            $payload['subtitle_case_id'] = $subtitleCaseId;
        }

        return [
            'payload' => $payload,
            'force_requires_approval' => $candidateRequiresApproval
                || ($isSeasonPack
                    && $this->mediaReplacementSettings->seasonPackPolicy() === SeasonPackPolicy::ApprovalRequired),
        ];
    }
}
