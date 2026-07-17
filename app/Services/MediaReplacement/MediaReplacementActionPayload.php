<?php

declare(strict_types=1);

namespace App\Services\MediaReplacement;

final readonly class MediaReplacementActionPayload
{
    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $effectiveLanguages
     * @param  list<string>  $matchedRules
     * @return array<string, mixed>
     */
    public function build(
        array $target,
        array $candidate,
        array $effectiveLanguages,
        array $matchedRules,
        string $selectionMode,
        string $reason,
        ?int $subtitleCaseId = null,
    ): array {
        $payload = [
            'title' => sprintf('Replace %s', $target['display_name'] ?? 'media file'),
            'detail' => $reason,
            'service' => $target['service'] ?? null,
            'service_connection_id' => $target['service_connection_id'] ?? null,
            'scope' => $target['scope'] ?? null,
            'target' => $target,
            'candidate_fingerprint' => $candidate['fingerprint'] ?? null,
            'candidate' => $candidate,
            'required_languages' => $effectiveLanguages,
            'confidence' => $candidate['confidence'] ?? 0,
            'matched_rules' => $matchedRules,
            'selection_mode' => $selectionMode,
            'agent_rationale' => mb_substr($reason, 0, 1000),
            'original_history_id' => $target['original_history_id'] ?? null,
        ];

        if ($subtitleCaseId !== null) {
            $payload['subtitle_case_id'] = $subtitleCaseId;
        }

        return $payload;
    }
}
