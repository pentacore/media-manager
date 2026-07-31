<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

use App\Enums\SeasonPackPolicy;
use App\Settings\MediaReplacementSettings;

/**
 * Builds the `replace_media_file` ActionRequest payload and its approval
 * override. Both the AI tool and the automatic subtitle check dispatch the same
 * action, whose fourteen-key payload — plus an optional auto-check key — is
 * read by MediaReplacementActions and the approval card; a second hand-built
 * copy of that shape would drift.
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
     * @return array{payload: array<string, mixed>, force_requires_approval: bool}
     */
    public function build(
        array $snapshot,
        array $candidate,
        array $requiredLanguages,
        string $selectionMode,
        string $reason,
        ?string $autoCheckKey = null,
    ): array {
        $isSeasonPack = ($candidate['season_pack'] ?? false) === true;
        $candidateRequiresApproval = ($candidate['requires_approval'] ?? false) === true;

        $payload = [
            'title' => sprintf('Replace %s', $snapshot['display_name'] ?? 'media file'),
            'detail' => $reason,
            'service' => $snapshot['service'] ?? null,
            'service_connection_id' => $snapshot['service_connection_id'] ?? null,
            'scope' => $snapshot['scope'] ?? null,
            'target' => $snapshot,
            'candidate_fingerprint' => $candidate['fingerprint'] ?? null,
            'candidate' => $candidate,
            'required_languages' => $requiredLanguages,
            'confidence' => $candidate['confidence'] ?? null,
            'matched_rules' => $candidate['matched_rules'] ?? null,
            'selection_mode' => $selectionMode,
            'agent_rationale' => mb_substr($reason, 0, 1000),
            'original_history_id' => $snapshot['original_history_id'] ?? null,
        ];

        if ($autoCheckKey !== null) {
            $payload['auto_check_key'] = $autoCheckKey;
        }

        return [
            'payload' => $payload,
            'force_requires_approval' => $candidateRequiresApproval
                || ($isSeasonPack
                    && $this->mediaReplacementSettings->seasonPackPolicy() === SeasonPackPolicy::ApprovalRequired),
        ];
    }
}
